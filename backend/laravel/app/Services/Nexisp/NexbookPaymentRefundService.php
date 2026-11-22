<?php

namespace App\Services\Nexisp;

use App\Models\NexbookInvoice;
use App\Models\NexbookPayment;
use App\Models\NexbookPaymentRefund;
use App\Models\NexispAccount;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NexbookPaymentRefundService
{
    public function __construct(private readonly JournalPostingService $journalPosting)
    {
    }

    /**
     * Refund previously received and allocated customer funds.
     * Refunds reverse Accounts Receivable and the selected cash/bank asset;
     * the original receipt and allocation history remain immutable.
     */
    public function refund(
        int $organizationId,
        int $paymentId,
        int $depositAccountId,
        int $receivableAccountId,
        string $refundDate,
        string $method,
        string $reason,
        array $allocations,
        ?string $reference = null,
        ?int $actorUserId = null,
    ): NexbookPaymentRefund {
        return DB::transaction(function () use (
            $organizationId, $paymentId, $depositAccountId, $receivableAccountId,
            $refundDate, $method, $reason, $allocations, $reference, $actorUserId
        ) {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

            if (!$this->validDate($refundDate)) {
                throw ValidationException::withMessages(['refund_date' => 'Refund date must be a valid ISO date.']);
            }
            if (!in_array($method, ['cash', 'bank_transfer', 'mobile_money', 'card', 'cheque', 'other'], true)) {
                throw ValidationException::withMessages(['method' => 'Choose a supported refund method.']);
            }
            $reason = trim($reason);
            if ($reason === '' || mb_strlen($reason) > 500) {
                throw ValidationException::withMessages(['reason' => 'A refund reason of 1 to 500 characters is required.']);
            }
            if ($reference !== null && mb_strlen($reference) > 120) {
                throw ValidationException::withMessages(['reference' => 'Refund reference cannot exceed 120 characters.']);
            }
            if (!is_array($allocations) || count($allocations) < 1 || count($allocations) > 100) {
                throw ValidationException::withMessages(['allocations' => 'A refund must be allocated to at least one original invoice allocation.']);
            }

            $payment = NexbookPayment::query()
                ->where('organization_id', $organizationId)
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($payment->status !== 'posted') {
                throw ValidationException::withMessages(['payment' => 'Only a posted receipt can be refunded.']);
            }

            $normalised = [];
            $refundMinor = 0;
            $invoiceIds = [];
            foreach ($allocations as $index => $allocation) {
                $invoiceId = (int) ($allocation['invoice_id'] ?? 0);
                $amountMinor = $this->minorUnits($allocation['amount'] ?? null);
                if ($invoiceId < 1 || $amountMinor < 1 || in_array($invoiceId, $invoiceIds, true)) {
                    throw ValidationException::withMessages(["allocations.{$index}" => 'Each invoice must appear once with a positive refund amount.']);
                }
                $invoiceIds[] = $invoiceId;
                $refundMinor += $amountMinor;
                $normalised[] = ['invoice_id' => $invoiceId, 'minor' => $amountMinor, 'amount' => $this->decimalAmount($amountMinor)];
            }

            $originalAllocations = DB::table('nexbook_payment_allocations')
                ->where('payment_id', $payment->id)
                ->whereIn('invoice_id', $invoiceIds)
                ->get()
                ->keyBy('invoice_id');
            if ($originalAllocations->count() !== count($invoiceIds)) {
                throw ValidationException::withMessages(['allocations' => 'Refunds can only be allocated to invoices covered by the original receipt.']);
            }

            $invoices = NexbookInvoice::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($invoices->count() !== count($invoiceIds)) {
                throw ValidationException::withMessages(['allocations' => 'Every refund invoice must belong to this organization.']);
            }

            foreach ($normalised as $index => $allocation) {
                $invoice = $invoices->get($allocation['invoice_id']);
                if ($invoice->currency !== $payment->currency) {
                    throw ValidationException::withMessages(["allocations.{$index}" => 'Refund invoice currency must match the original receipt.']);
                }

                $previousRefunds = DB::table('nexbook_payment_refund_allocations as allocations')
                    ->join('nexbook_payment_refunds as refunds', 'refunds.id', '=', 'allocations.refund_id')
                    ->where('refunds.organization_id', $organizationId)
                    ->where('refunds.payment_id', $payment->id)
                    ->where('refunds.status', 'posted')
                    ->where('allocations.invoice_id', $invoice->id)
                    ->sum('allocations.amount');

                $refundableFromReceipt = $this->minorUnits($originalAllocations->get($invoice->id)->amount)
                    - $this->minorUnits($previousRefunds);
                if ($allocation['minor'] > $refundableFromReceipt) {
                    throw ValidationException::withMessages(["allocations.{$index}.amount" => 'Refund cannot exceed the remaining amount allocated to this invoice on the original receipt.']);
                }
                if ($allocation['minor'] > $this->minorUnits($invoice->amount_paid)) {
                    throw ValidationException::withMessages(["allocations.{$index}.amount" => 'Refund cannot exceed the amount currently recorded as paid on this invoice.']);
                }
            }

            if ($refundMinor > $this->minorUnits($payment->amount)) {
                throw ValidationException::withMessages(['allocations' => 'Refund total cannot exceed the original receipt amount.']);
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
                throw ValidationException::withMessages(['deposit_account_id' => 'The refund bank/cash account must be an active asset account belonging to this organization.']);
            }
            if (!$receivable || $receivable->type !== 'asset') {
                throw ValidationException::withMessages(['receivable_account_id' => 'The receivable account must be an active asset account belonging to this organization.']);
            }

            $year = substr($refundDate, 0, 4);
            $prefix = "RF-{$year}-";
            $lastNumber = NexbookPaymentRefund::query()
                ->where('organization_id', $organizationId)
                ->where('refund_number', 'like', $prefix . '%')
                ->orderByDesc('id')
                ->value('refund_number');
            $sequence = $lastNumber ? ((int) substr($lastNumber, strlen($prefix))) + 1 : 1;
            $number = $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

            $refund = NexbookPaymentRefund::query()->create([
                'organization_id' => $organizationId,
                'payment_id' => $payment->id,
                'created_by' => $actorUserId,
                'refund_number' => $number,
                'status' => 'draft',
                'refund_date' => $refundDate,
                'currency' => $payment->currency,
                'method' => $method,
                'reference' => $reference,
                'reason' => $reason,
                'amount' => $this->decimalAmount($refundMinor),
            ]);
            $refund->allocations()->createMany(array_map(
                fn (array $allocation) => [
                    'invoice_id' => $allocation['invoice_id'],
                    'amount' => $allocation['amount'],
                ],
                $normalised
            ));

            $entry = $this->journalPosting->post(
                organizationId: $organizationId,
                entryNumber: 'JE-' . $number,
                entryDate: $refundDate,
                description: "Refund {$number} against receipt {$payment->receipt_number}",
                lines: [
                    [
                        'account_id' => $receivableAccountId,
                        'debit' => $this->decimalAmount($refundMinor),
                        'credit' => '0.00',
                        'memo' => "Reverse receivable for refund {$number}",
                    ],
                    [
                        'account_id' => $depositAccountId,
                        'debit' => '0.00',
                        'credit' => $this->decimalAmount($refundMinor),
                        'memo' => "Funds refunded via {$method}",
                    ],
                ],
                actorUserId: $actorUserId,
                currency: $payment->currency,
                sourceType: 'nexbook_payment_refund',
                sourceId: (string) $refund->id,
            );

            foreach ($normalised as $allocation) {
                $invoice = $invoices->get($allocation['invoice_id']);
                $newPaidMinor = $this->minorUnits($invoice->amount_paid) - $allocation['minor'];
                $invoice->forceFill([
                    'amount_paid' => $this->decimalAmount($newPaidMinor),
                    'status' => $newPaidMinor === 0 ? 'issued' : 'partially_paid',
                ])->save();
            }

            $refund->forceFill([
                'journal_entry_id' => $entry->id,
                'status' => 'posted',
                'posted_at' => now(),
            ])->save();

            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId,
                'actor_user_id' => $actorUserId,
                'action' => 'nexbook.payment.refunded',
                'subject_type' => NexbookPaymentRefund::class,
                'subject_id' => (string) $refund->id,
                'metadata' => json_encode([
                    'refund_number' => $number,
                    'receipt_number' => $payment->receipt_number,
                    'journal_entry_id' => $entry->id,
                    'amount' => $refund->amount,
                    'currency' => $refund->currency,
                    'invoice_ids' => $invoiceIds,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $refund->fresh(['allocations.invoice', 'journalEntry.lines.account', 'payment']);
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
