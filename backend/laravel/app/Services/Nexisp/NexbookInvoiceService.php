<?php

namespace App\Services\Nexisp;

use App\Models\NexbookInvoice;
use App\Models\NexispAccount;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NexbookInvoiceService
{
    public function __construct(private readonly JournalPostingService $journalPosting)
    {
    }

    /**
     * Create a numbered draft invoice. The organization row lock serializes
     * sequence allocation so concurrent requests cannot receive the same number.
     */
    public function createDraft(int $organizationId, array $data, ?int $actorUserId = null): NexbookInvoice
    {
        return DB::transaction(function () use ($organizationId, $data, $actorUserId) {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

            $issueDate = $data['issue_date'] ?? null;
            $dueDate = $data['due_date'] ?? null;
            $currency = strtoupper((string) ($data['currency'] ?? 'KES'));
            $taxRate = (int) ($data['tax_rate_basis_points'] ?? 0);
            $lines = $data['lines'] ?? [];
            $customerName = trim((string) ($data['customer_name'] ?? ''));
            if ($customerName === '' || mb_strlen($customerName) > 160) {
                throw ValidationException::withMessages(['customer_name' => 'A customer name of 1 to 160 characters is required.']);
            }
            if (!empty($data['customer_email']) && !filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['customer_email' => 'Enter a valid customer email address.']);
            }

            if (!$this->validDate($issueDate) || !$this->validDate($dueDate) || $dueDate < $issueDate) {
                throw ValidationException::withMessages(['due_date' => 'Issue and due dates must be valid ISO dates, and the due date cannot precede the issue date.']);
            }
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                throw ValidationException::withMessages(['currency' => 'Use a valid three-letter ISO currency code.']);
            }
            if ($taxRate < 0 || $taxRate > 10000) {
                throw ValidationException::withMessages(['tax_rate_basis_points' => 'Tax rate must be between 0 and 100 percent.']);
            }
            if (!is_array($lines) || count($lines) < 1 || count($lines) > 100) {
                throw ValidationException::withMessages(['lines' => 'An invoice must contain between 1 and 100 lines.']);
            }

            $normalisedLines = [];
            $subtotalMinor = 0;
            $accountIds = [];
            foreach ($lines as $index => $line) {
                $accountId = (int) ($line['revenue_account_id'] ?? 0);
                $quantityMilli = $this->quantityMilli($line['quantity'] ?? '1');
                $unitPriceMinor = $this->moneyMinor($line['unit_price'] ?? null);
                $description = trim((string) ($line['description'] ?? ''));

                if ($accountId < 1 || $description === '' || mb_strlen($description) > 255 || $quantityMilli < 1 || $unitPriceMinor < 1) {
                    throw ValidationException::withMessages(["lines.{$index}" => 'Each invoice line needs a valid revenue account, description, positive quantity and positive unit price.']);
                }

                $lineTotalMinor = intdiv(($quantityMilli * $unitPriceMinor) + 500, 1000);
                if ($lineTotalMinor < 1) {
                    throw ValidationException::withMessages(["lines.{$index}" => 'The line total must be at least one minor currency unit.']);
                }
                $subtotalMinor += $lineTotalMinor;
                $accountIds[] = $accountId;
                $normalisedLines[] = [
                    'revenue_account_id' => $accountId,
                    'description' => $description,
                    'quantity' => $this->decimalQuantity($quantityMilli),
                    'unit_price' => $this->decimalMoney($unitPriceMinor),
                    'line_total' => $this->decimalMoney($lineTotalMinor),
                ];
            }

            $accounts = NexispAccount::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->whereIn('id', array_values(array_unique($accountIds)))
                ->get()
                ->keyBy('id');

            foreach ($normalisedLines as $index => $line) {
                $account = $accounts->get($line['revenue_account_id']);
                if (!$account || !in_array($account->type, ['revenue', 'income'], true)) {
                    throw ValidationException::withMessages(["lines.{$index}.revenue_account_id" => 'Revenue accounts must be active and belong to this organization.']);
                }
            }

            $taxMinor = intdiv(($subtotalMinor * $taxRate) + 5000, 10000);
            $totalMinor = $subtotalMinor + $taxMinor;
            $year = substr($issueDate, 0, 4);
            $prefix = "NB-{$year}-";
            $lastNumber = NexbookInvoice::query()
                ->where('organization_id', $organizationId)
                ->where('invoice_number', 'like', $prefix . '%')
                ->orderByDesc('id')
                ->value('invoice_number');
            $sequence = $lastNumber ? ((int) substr($lastNumber, strlen($prefix))) + 1 : 1;
            $invoiceNumber = $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

            $invoice = NexbookInvoice::query()->create([
                'organization_id' => $organizationId,
                'created_by' => $actorUserId,
                'customer_user_id' => $data['customer_user_id'] ?? null,
                'invoice_number' => $invoiceNumber,
                'status' => 'draft',
                'customer_name' => $customerName,
                'customer_email' => $data['customer_email'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'currency' => $currency,
                'subtotal' => $this->decimalMoney($subtotalMinor),
                'tax_total' => $this->decimalMoney($taxMinor),
                'total' => $this->decimalMoney($totalMinor),
                'amount_paid' => '0.00',
                'metadata' => ['tax_rate_basis_points' => $taxRate],
            ]);

            $invoice->lines()->createMany($normalisedLines);

            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId,
                'actor_user_id' => $actorUserId,
                'action' => 'nexbook.invoice.draft_created',
                'subject_type' => NexbookInvoice::class,
                'subject_id' => (string) $invoice->id,
                'metadata' => json_encode(['invoice_number' => $invoiceNumber, 'currency' => $currency, 'total' => $invoice->total], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $invoice->load('lines.revenueAccount');
        });
    }

    /**
     * Issue a draft invoice and post its receivable/revenue/tax journal atomically.
     * Posted invoices are immutable through this service; corrections use credit notes.
     */
    public function issue(
        int $organizationId,
        int $invoiceId,
        int $receivableAccountId,
        ?int $taxPayableAccountId = null,
        ?int $actorUserId = null,
    ): NexbookInvoice {
        return DB::transaction(function () use (
            $organizationId, $invoiceId, $receivableAccountId, $taxPayableAccountId, $actorUserId
        ) {
            $invoice = NexbookInvoice::query()
                ->where('organization_id', $organizationId)
                ->whereKey($invoiceId)
                ->lockForUpdate()
                ->with('lines')
                ->firstOrFail();

            if ($invoice->status !== 'draft' || $invoice->journal_entry_id !== null) {
                throw ValidationException::withMessages(['invoice' => 'Only an unissued draft invoice can be issued.']);
            }
            if ($invoice->lines->isEmpty() || $this->moneyMinor($invoice->total) < 1) {
                throw ValidationException::withMessages(['invoice' => 'An invoice must contain positive billable lines before issue.']);
            }

            $accountIds = [$receivableAccountId];
            foreach ($invoice->lines as $line) {
                $accountIds[] = (int) $line->revenue_account_id;
            }
            if ($this->moneyMinor($invoice->tax_total) > 0) {
                if ($taxPayableAccountId === null) {
                    throw ValidationException::withMessages(['tax_payable_account_id' => 'A tax payable account is required when the invoice contains tax.']);
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

            $journalLines = [[
                'account_id' => $receivableAccountId,
                'debit' => $invoice->total,
                'credit' => '0.00',
                'memo' => "Receivable for {$invoice->invoice_number}",
            ]];

            foreach ($invoice->lines as $line) {
                $revenue = $accounts->get((int) $line->revenue_account_id);
                if (!$revenue || !in_array($revenue->type, ['revenue', 'income'], true)) {
                    throw ValidationException::withMessages(['invoice' => 'An invoice revenue account is no longer active or valid for this organization.']);
                }
                $journalLines[] = [
                    'account_id' => (int) $line->revenue_account_id,
                    'debit' => '0.00',
                    'credit' => $line->line_total,
                    'memo' => $line->description,
                ];
            }

            $taxMinor = $this->moneyMinor($invoice->tax_total);
            if ($taxMinor > 0) {
                $taxAccount = $accounts->get($taxPayableAccountId);
                if (!$taxAccount || $taxAccount->type !== 'liability') {
                    throw ValidationException::withMessages(['tax_payable_account_id' => 'The tax payable account must be an active liability account belonging to this organization.']);
                }
                $journalLines[] = [
                    'account_id' => $taxPayableAccountId,
                    'debit' => '0.00',
                    'credit' => $invoice->tax_total,
                    'memo' => "Tax on {$invoice->invoice_number}",
                ];
            }

            $entry = $this->journalPosting->post(
                organizationId: $organizationId,
                entryNumber: 'JE-' . $invoice->invoice_number,
                entryDate: $invoice->issue_date->format('Y-m-d'),
                description: "Invoice {$invoice->invoice_number} issued to {$invoice->customer_name}",
                lines: $journalLines,
                actorUserId: $actorUserId,
                currency: $invoice->currency,
                sourceType: 'nexbook_invoice',
                sourceId: (string) $invoice->id,
            );

            $invoice->forceFill([
                'journal_entry_id' => $entry->id,
                'status' => 'issued',
                'issued_at' => now(),
            ])->save();

            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId,
                'actor_user_id' => $actorUserId,
                'action' => 'nexbook.invoice.issued',
                'subject_type' => NexbookInvoice::class,
                'subject_id' => (string) $invoice->id,
                'metadata' => json_encode([
                    'invoice_number' => $invoice->invoice_number,
                    'journal_entry_id' => $entry->id,
                    'currency' => $invoice->currency,
                    'total' => $invoice->total,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $invoice->fresh(['lines.revenueAccount', 'journalEntry.lines.account']);
        });
    }

    private function validDate(mixed $date): bool
    {
        if (!is_string($date)) {
            return false;
        }
        $timestamp = strtotime($date);
        return $timestamp !== false && date('Y-m-d', $timestamp) === $date;
    }

    private function moneyMinor(mixed $amount): int
    {
        $value = (string) $amount;
        if (!preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Amounts must be positive decimal values with no more than two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function quantityMilli(mixed $quantity): int
    {
        $value = (string) $quantity;
        if (!preg_match('/^(?:0|[1-9][0-9]{0,3})(?:\.[0-9]{1,3})?$/', $value)) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be a positive number with no more than three decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 1000) + (int) str_pad($fraction, 3, '0');
    }

    private function decimalMoney(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function decimalQuantity(int $milli): string
    {
        return intdiv($milli, 1000) . '.' . str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }
}
