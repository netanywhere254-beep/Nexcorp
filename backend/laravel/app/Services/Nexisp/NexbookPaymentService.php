<?php

namespace App\Services\Nexisp;

use App\Models\NexbookInvoice;
use App\Models\NexbookPayment;
use App\Models\NexispAccount;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NexbookPaymentService
{
    public function __construct(private readonly JournalPostingService $journalPosting)
    {
    }

    /**
     * Record a receipt and allocate it to one or more outstanding invoices.
     * The receipt, allocations, invoice balances and ledger journal commit together.
     */
    public function receive(
        int $organizationId,
        int $depositAccountId,
        int $receivableAccountId,
        string $paymentDate,
        string $currency,
        string $method,
        string $amount,
        array $allocations,
        ?string $reference = null,
        ?int $actorUserId = null,
    ): NexbookPayment {
        return DB::transaction(function () use (
            $organizationId, $depositAccountId, $receivableAccountId, $paymentDate,
            $currency, $method, $amount, $allocations, $reference, $actorUserId
        ) {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

            if (!$this->validDate($paymentDate)) {
                throw ValidationException::withMessages(['payment_date' => 'Payment date must be a valid ISO date.']);
            }
            $currency = strtoupper($currency);
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                throw ValidationException::withMessages(['currency' => 'Use a valid three-letter ISO currency code.']);
            }
            if (!in_array($method, ['cash', 'bank_transfer', 'mobile_money', 'card', 'cheque', 'other'], true)) {
                throw ValidationException::withMessages(['method' => 'Choose a supported payment method.']);
            }
            if ($reference !== null && mb_strlen($reference) > 120) {
                throw ValidationException::withMessages(['reference' => 'Payment reference cannot exceed 120 characters.']);
            }

            $amountMinor = $this->minorUnits($amount);
            if ($amountMinor < 1 || count($allocations) < 1 || count($allocations) > 100) {
                throw ValidationException::withMessages(['allocations' => 'A positive receipt amount and at least one invoice allocation are required.']);
            }

            $allocationRows = [];
            $allocationTotal = 0;
            $invoiceIds = [];
            foreach ($allocations as $index => $allocation) {
                $invoiceId = (int) ($allocation['invoice_id'] ?? 0);
                $allocationMinor = $this->minorUnits($allocation['amount'] ?? null);
                if ($invoiceId < 1 || $allocationMinor < 1 || in_array($invoiceId, $invoiceIds, true)) {
                    throw ValidationException::withMessages(["allocations.{$index}" => 'Each invoice must appear once with a positive allocation amount.']);
                }
                $invoiceIds[] = $invoiceId;
                $allocationTotal += $allocationMinor;
                $allocationRows[] = [
                    'invoice_id' => $invoiceId,
                    'amount' => $this->decimalAmount($allocationMinor),
                    'minor' => $allocationMinor,
                ];
            }

            if ($allocationTotal !== $amountMinor) {
                throw ValidationException::withMessages(['allocations' => 'The allocations must add up exactly to the receipt amount. Unallocated customer credits are not enabled in this workflow.']);
            }

            $invoices = NexbookInvoice::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($invoices->count() !== count($invoiceIds)) {
                throw ValidationException::withMessages(['allocations' => 'Every allocated invoice must belong to this organization.']);
            }

            $customerIdentity = null;
            foreach ($allocationRows as $index => $allocation) {
                $invoice = $invoices->get($allocation['invoice_id']);
                if (!in_array($invoice->status, ['issued', 'partially_paid'], true)
                    || $invoice->currency !== $currency) {
                    throw ValidationException::withMessages(["allocations.{$index}" => 'Only issued or partially paid invoices in the receipt currency can receive payments.']);
                }

                $identity = $invoice->customer_user_id
                    ? 'user:' . $invoice->customer_user_id
                    : 'name:' . mb_strtolower(trim($invoice->customer_name));
                if ($customerIdentity !== null && $customerIdentity !== $identity) {
                    throw ValidationException::withMessages(['allocations' => 'A single receipt can only be allocated to invoices for one customer.']);
                }
                $customerIdentity = $identity;

                $outstanding = $this->minorUnits($invoice->total) - $this->minorUnits($invoice->amount_paid);
                if ($allocation['minor'] > $outstanding) {
                    throw ValidationException::withMessages(["allocations.{$index}.amount" => 'An allocation cannot exceed the invoice outstanding balance.']);
                }
            }

            $accounts = NexispAccount::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->whereIn('id', array_values(array_unique([$depositAccountId, $receivableAccountId])))
                ->get()
                ->keyBy('id');
            $deposit = $accounts->get($depositAccountId);
            $receivable = $accounts->get($receivableAccountId);
            if (!$deposit || $deposit->type !== 'asset') {
                throw ValidationException::withMessages(['deposit_account_id' => 'The deposit account must be an active asset account belonging to this organization.']);
            }
            if (!$receivable || $receivable->type !== 'asset') {
                throw ValidationException::withMessages(['receivable_account_id' => 'The receivable account must be an active asset account belonging to this organization.']);
            }

            $year = substr($paymentDate, 0, 4);
            $prefix = "RCPT-{$year}-";
            $lastNumber = NexbookPayment::query()
                ->where('organization_id', $organizationId)
                ->where('receipt_number', 'like', $prefix . '%')
                ->orderByDesc('id')
                ->value('receipt_number');
            $sequence = $lastNumber ? ((int) substr($lastNumber, strlen($prefix))) + 1 : 1;
            $receiptNumber = $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

            $payment = NexbookPayment::query()->create([
                'organization_id' => $organizationId,
                'received_by' => $actorUserId,
                'receipt_number' => $receiptNumber,
                'status' => 'draft',
                'payment_date' => $paymentDate,
                'currency' => $currency,
                'method' => $method,
                'reference' => $reference,
                'amount' => $this->decimalAmount($amountMinor),
                'allocated_amount' => $this->decimalAmount($allocationTotal),
            ]);

            foreach ($allocationRows as $allocation) {
                $payment->allocations()->create([
                    'invoice_id' => $allocation['invoice_id'],
                    'amount' => $allocation['amount'],
                ]);
            }

            $entry = $this->journalPosting->post(
                organizationId: $organizationId,
                entryNumber: 'JE-' . $receiptNumber,
                entryDate: $paymentDate,
                description: "Receipt {$receiptNumber}",
                lines: [
                    [
                        'account_id' => $depositAccountId,
                        'debit' => $this->decimalAmount($amountMinor),
                        'credit' => '0.00',
                        'memo' => "Funds received via {$method}",
                    ],
                    [
                        'account_id' => $receivableAccountId,
                        'debit' => '0.00',
                        'credit' => $this->decimalAmount($amountMinor),
                        'memo' => "Apply receipt {$receiptNumber} to accounts receivable",
                    ],
                ],
                actorUserId: $actorUserId,
                currency: $currency,
                sourceType: 'nexbook_payment',
                sourceId: (string) $payment->id,
            );

            foreach ($allocationRows as $allocation) {
                $invoice = $invoices->get($allocation['invoice_id']);
                $newPaidMinor = $this->minorUnits($invoice->amount_paid) + $allocation['minor'];
                $invoiceTotalMinor = $this->minorUnits($invoice->total);
                $invoice->forceFill([
                    'amount_paid' => $this->decimalAmount($newPaidMinor),
                    'status' => $newPaidMinor === $invoiceTotalMinor ? 'paid' : 'partially_paid',
                ])->save();
            }

            $payment->forceFill([
                'journal_entry_id' => $entry->id,
                'status' => 'posted',
                'posted_at' => now(),
            ])->save();

            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId,
                'actor_user_id' => $actorUserId,
                'action' => 'nexbook.payment.received',
                'subject_type' => NexbookPayment::class,
                'subject_id' => (string) $payment->id,
                'metadata' => json_encode([
                    'receipt_number' => $receiptNumber,
                    'journal_entry_id' => $entry->id,
                    'currency' => $currency,
                    'amount' => $payment->amount,
                    'invoice_ids' => $invoiceIds,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $payment->fresh(['allocations.invoice', 'journalEntry.lines.account']);
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
        if (!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Amounts must be positive decimal values with no more than two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function decimalAmount(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
