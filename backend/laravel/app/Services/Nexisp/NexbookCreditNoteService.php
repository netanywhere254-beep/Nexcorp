<?php

namespace App\Services\Nexisp;

use App\Models\NexbookCreditNote;
use App\Models\NexbookInvoice;
use App\Models\NexispAccount;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NexbookCreditNoteService
{
    public function __construct(private readonly JournalPostingService $journalPosting)
    {
    }

    /**
     * Create and issue a credit note against an unpaid invoice balance.
     * Refunds of already-paid amounts are deliberately handled by a separate
     * refund workflow, never by silently creating a negative receivable.
     */
    public function issue(
        int $organizationId,
        int $invoiceId,
        int $receivableAccountId,
        ?int $taxPayableAccountId,
        string $creditDate,
        string $reason,
        array $lines,
        ?int $actorUserId = null,
    ): NexbookCreditNote {
        return DB::transaction(function () use (
            $organizationId, $invoiceId, $receivableAccountId, $taxPayableAccountId,
            $creditDate, $reason, $lines, $actorUserId
        ) {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

            if (!$this->validDate($creditDate)) {
                throw ValidationException::withMessages(['credit_date' => 'Credit date must be a valid ISO date.']);
            }
            $reason = trim($reason);
            if ($reason === '' || mb_strlen($reason) > 500) {
                throw ValidationException::withMessages(['reason' => 'A credit note reason of 1 to 500 characters is required.']);
            }
            if (!is_array($lines) || count($lines) < 1 || count($lines) > 100) {
                throw ValidationException::withMessages(['lines' => 'A credit note must contain between 1 and 100 lines.']);
            }

            $invoice = NexbookInvoice::query()
                ->where('organization_id', $organizationId)
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->with('lines')
                ->firstOrFail();

            if (!in_array($invoice->status, ['issued', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['invoice' => 'Credit notes can only be issued against an issued or partially paid invoice.']);
            }
            if ($creditDate < $invoice->issue_date->format('Y-m-d')) {
                throw ValidationException::withMessages(['credit_date' => 'Credit date cannot precede the original invoice date.']);
            }

            $existingLines = DB::table('nexbook_credit_note_lines as credit_lines')
                ->join('nexbook_credit_notes as notes', 'notes.id', '=', 'credit_lines.credit_note_id')
                ->where('notes.organization_id', $organizationId)
                ->where('notes.invoice_id', $invoice->id)
                ->where('notes.status', 'issued')
                ->select('credit_lines.invoice_line_id', DB::raw('SUM(credit_lines.amount) as credited_amount'))
                ->groupBy('credit_lines.invoice_line_id')
                ->get()
                ->keyBy('invoice_line_id');

            $invoiceLines = $invoice->lines->keyBy('id');
            $normalised = [];
            $subtotalMinor = 0;
            $seen = [];

            foreach ($lines as $index => $line) {
                $invoiceLineId = (int) ($line['invoice_line_id'] ?? 0);
                $amountMinor = $this->minorUnits($line['amount'] ?? null);
                if ($invoiceLineId < 1 || $amountMinor < 1 || in_array($invoiceLineId, $seen, true)) {
                    throw ValidationException::withMessages(["lines.{$index}" => 'Each original invoice line must be referenced once with a positive credit amount.']);
                }
                $seen[] = $invoiceLineId;
                $original = $invoiceLines->get($invoiceLineId);
                if (!$original) {
                    throw ValidationException::withMessages(["lines.{$index}.invoice_line_id" => 'The selected line does not belong to this invoice.']);
                }

                $alreadyCredited = $this->minorUnits($existingLines->get($invoiceLineId)->credited_amount ?? '0.00');
                $remaining = $this->minorUnits($original->line_total) - $alreadyCredited;
                if ($amountMinor > $remaining) {
                    throw ValidationException::withMessages(["lines.{$index}.amount" => 'The credit amount cannot exceed the remaining value of the original invoice line.']);
                }

                $subtotalMinor += $amountMinor;
                $normalised[] = [
                    'invoice_line_id' => $invoiceLineId,
                    'revenue_account_id' => (int) $original->revenue_account_id,
                    'description' => 'Credit: ' . $original->description,
                    'amount' => $this->decimalAmount($amountMinor),
                ];
            }

            $taxRate = (int) ($invoice->metadata['tax_rate_basis_points'] ?? 0);
            $taxMinor = intdiv(($subtotalMinor * $taxRate) + 5000, 10000);
            $totalMinor = $subtotalMinor + $taxMinor;

            $alreadyCreditedTotal = DB::table('nexbook_credit_notes')
                ->where('organization_id', $organizationId)
                ->where('invoice_id', $invoice->id)
                ->where('status', 'issued')
                ->sum('total');
            $remainingReceivable = $this->minorUnits($invoice->total)
                - $this->minorUnits($invoice->amount_paid)
                - $this->minorUnits($alreadyCreditedTotal);
            if ($totalMinor > $remainingReceivable) {
                throw ValidationException::withMessages(['lines' => 'A credit note cannot exceed the invoice outstanding balance. Paid amounts must use the separate refund workflow.']);
            }

            $accountIds = [$receivableAccountId];
            foreach ($normalised as $line) {
                $accountIds[] = $line['revenue_account_id'];
            }
            if ($taxMinor > 0) {
                if ($taxPayableAccountId === null) {
                    throw ValidationException::withMessages(['tax_payable_account_id' => 'A tax payable account is required for a taxable credit note.']);
                }
                $accountIds[] = $taxPayableAccountId;
            }

            $accounts = NexispAccount::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->whereIn('id', array_values(array_unique($accountIds)))
                ->get()
                ->keyBy('id');
            $receivable = $accounts->get($receivableAccountId);
            if (!$receivable || $receivable->type !== 'asset') {
                throw ValidationException::withMessages(['receivable_account_id' => 'The receivable account must be an active asset account belonging to this organization.']);
            }

            $year = substr($creditDate, 0, 4);
            $prefix = "CN-{$year}-";
            $lastNumber = NexbookCreditNote::query()
                ->where('organization_id', $organizationId)
                ->where('credit_note_number', 'like', $prefix . '%')
                ->orderByDesc('id')
                ->value('credit_note_number');
            $sequence = $lastNumber ? ((int) substr($lastNumber, strlen($prefix))) + 1 : 1;
            $number = $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

            $note = NexbookCreditNote::query()->create([
                'organization_id' => $organizationId,
                'invoice_id' => $invoice->id,
                'created_by' => $actorUserId,
                'credit_note_number' => $number,
                'status' => 'draft',
                'reason' => $reason,
                'credit_date' => $creditDate,
                'currency' => $invoice->currency,
                'subtotal' => $this->decimalAmount($subtotalMinor),
                'tax_total' => $this->decimalAmount($taxMinor),
                'total' => $this->decimalAmount($totalMinor),
                'metadata' => ['tax_rate_basis_points' => $taxRate],
            ]);
            $note->lines()->createMany($normalised);

            $journalLines = [];
            foreach ($normalised as $line) {
                $revenue = $accounts->get($line['revenue_account_id']);
                if (!$revenue || !in_array($revenue->type, ['revenue', 'income'], true)) {
                    throw ValidationException::withMessages(['lines' => 'An original revenue account is no longer active or valid for this organization.']);
                }
                $journalLines[] = [
                    'account_id' => $line['revenue_account_id'],
                    'debit' => $line['amount'],
                    'credit' => '0.00',
                    'memo' => $line['description'],
                ];
            }

            if ($taxMinor > 0) {
                $taxAccount = $accounts->get($taxPayableAccountId);
                if (!$taxAccount || $taxAccount->type !== 'liability') {
                    throw ValidationException::withMessages(['tax_payable_account_id' => 'The tax payable account must be an active liability account belonging to this organization.']);
                }
                $journalLines[] = [
                    'account_id' => $taxPayableAccountId,
                    'debit' => $this->decimalAmount($taxMinor),
                    'credit' => '0.00',
                    'memo' => "Tax reversal for {$number}",
                ];
            }

            $journalLines[] = [
                'account_id' => $receivableAccountId,
                'debit' => '0.00',
                'credit' => $this->decimalAmount($totalMinor),
                'memo' => "Credit against {$invoice->invoice_number}",
            ];

            $entry = $this->journalPosting->post(
                organizationId: $organizationId,
                entryNumber: 'JE-' . $number,
                entryDate: $creditDate,
                description: "Credit note {$number} against {$invoice->invoice_number}",
                lines: $journalLines,
                actorUserId: $actorUserId,
                currency: $invoice->currency,
                sourceType: 'nexbook_credit_note',
                sourceId: (string) $note->id,
            );

            $note->forceFill([
                'journal_entry_id' => $entry->id,
                'status' => 'issued',
                'issued_at' => now(),
            ])->save();

            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId,
                'actor_user_id' => $actorUserId,
                'action' => 'nexbook.credit_note.issued',
                'subject_type' => NexbookCreditNote::class,
                'subject_id' => (string) $note->id,
                'metadata' => json_encode([
                    'credit_note_number' => $number,
                    'invoice_id' => $invoice->id,
                    'journal_entry_id' => $entry->id,
                    'total' => $note->total,
                    'currency' => $note->currency,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $note->fresh(['lines.invoiceLine', 'journalEntry.lines.account', 'invoice']);
        });
    }

    private function validDate(string $date): bool
    {
        $timestamp = strtotime($date);
        return $timestamp !== false && date('Y-m-d', $timestamp) === $date;
    }

    private function minorUnits(mixed $amount): int
    {
        $value = (string) $amount;
        if (!preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Amounts must be valid non-negative decimal values.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function decimalAmount(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
