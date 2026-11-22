<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\NexispAccountingSetting;
use App\Models\NexispChartOfAccount;
use App\Models\NexispCustomer;
use App\Models\NexispExpense;
use App\Models\NexispInvoice;
use App\Models\NexispJournalEntry;
use App\Models\NexispReceipt;
use App\Models\Organization;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class NexispAccountingService
{
    private const DEFAULT_ACCOUNTS = [
        ['1000', 'Cash on hand', 'asset', 'debit'],
        ['1010', 'Bank account', 'asset', 'debit'],
        ['1100', 'Accounts receivable', 'asset', 'debit'],
        ['2000', 'Accounts payable', 'liability', 'credit'],
        ['2100', 'Tax payable', 'liability', 'credit'],
        ['3000', 'Owner equity', 'equity', 'credit'],
        ['4000', 'Internet subscription revenue', 'revenue', 'credit'],
        ['4100', 'Installation and service revenue', 'revenue', 'credit'],
        ['5000', 'Bandwidth and upstream costs', 'expense', 'debit'],
        ['5100', 'Network equipment and maintenance', 'expense', 'debit'],
        ['5200', 'Staff and operating expenses', 'expense', 'debit'],
        ['5300', 'Payment processing fees', 'expense', 'debit'],
    ];

    public function bootstrap(Organization $organization, User $actor): int
    {
        Gate::forUser($actor)->authorize('manageBilling', $organization);

        return DB::transaction(function () use ($organization, $actor): int {
            NexispAccountingSetting::firstOrCreate(
                ['organization_id' => $organization->id],
                ['operating_mode' => 'native', 'base_currency' => 'KES'],
            );

            $created = 0;
            foreach (self::DEFAULT_ACCOUNTS as [$code, $name, $type, $normal]) {
                $account = NexispChartOfAccount::firstOrCreate(
                    ['organization_id' => $organization->id, 'code' => $code],
                    [
                        'name' => $name,
                        'account_type' => $type,
                        'normal_balance' => $normal,
                        'system_account' => true,
                        'active' => true,
                    ],
                );
                $created += (int) $account->wasRecentlyCreated;
            }

            AuditLog::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'action' => 'nexisp.accounting.native_ledger_bootstrapped',
                'subject_type' => NexispAccountingSetting::class,
                'subject_id' => (string) NexispAccountingSetting::where('organization_id', $organization->id)->value('id'),
                'metadata' => ['default_accounts_created' => $created],
            ]);

            return $created;
        });
    }

    public function createExpense(
        User $actor,
        Organization $organization,
        array $data,
    ): NexispExpense {
        Gate::forUser($actor)->authorize('manageBilling', $organization);

        return DB::transaction(function () use ($actor, $organization, $data): NexispExpense {
            $settings = NexispAccountingSetting::firstOrCreate(
                ['organization_id' => $organization->id],
                ['operating_mode' => 'native', 'base_currency' => 'KES'],
            );
            if (! $settings->native_ledger_enabled || ! $settings->expenses_enabled) {
                throw ValidationException::withMessages(['feature' => 'Native ledger or expense tracking is disabled.']);
            }

            $expenseAccount = NexispChartOfAccount::query()
                ->where('organization_id', $organization->id)->whereKey($data['expense_account_id'])
                ->where('active', true)->firstOrFail();
            $paymentAccount = NexispChartOfAccount::query()
                ->where('organization_id', $organization->id)->whereKey($data['payment_account_id'])
                ->where('active', true)->firstOrFail();

            if ($expenseAccount->account_type !== 'expense' || ! in_array($paymentAccount->account_type, ['asset', 'liability'], true)) {
                throw ValidationException::withMessages(['accounts' => 'Choose an expense account and a valid cash, bank or payable account.']);
            }

            $amountCents = $this->toCents($data['amount']);
            $number = $this->nextNumber($organization, 'expense');

            $expense = NexispExpense::create([
                'organization_id' => $organization->id,
                'expense_number' => $number,
                'expense_account_id' => $expenseAccount->id,
                'vendor_name' => $data['vendor_name'] ?? null,
                'description' => $data['description'],
                'amount' => number_format($amountCents / 100, 2, '.', ''),
                'currency' => strtoupper($data['currency'] ?? $settings->base_currency),
                'status' => 'posted',
                'expense_date' => $data['expense_date'],
                'reference' => $data['reference'] ?? null,
                'created_by_user_id' => $actor->id,
            ]);

            $entry = $this->postJournal($organization, $actor, [
                'entry_number' => 'JE-'.strtoupper(uniqid()),
                'description' => 'Expense '.$number.': '.$data['description'],
                'currency' => $expense->currency,
                'entry_date' => $expense->expense_date->toDateString(),
                'source_type' => NexispExpense::class,
                'source_id' => $expense->id,
                'lines' => [
                    ['account_id' => $expenseAccount->id, 'debit' => $amountCents, 'credit' => 0, 'description' => $data['description']],
                    ['account_id' => $paymentAccount->id, 'debit' => 0, 'credit' => $amountCents, 'description' => $data['description']],
                ],
            ]);

            $expense->update(['journal_entry_id' => $entry->id]);

            AuditLog::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'action' => 'nexisp.accounting.expense_posted',
                'subject_type' => NexispExpense::class,
                'subject_id' => (string) $expense->id,
                'metadata' => ['expense_number' => $number, 'amount' => $expense->amount, 'currency' => $expense->currency],
            ]);

            return $expense->fresh();
        });
    }

    public function postJournal(Organization $organization, User $actor, array $data): NexispJournalEntry
    {
        $lines = $data['lines'] ?? [];
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => 'A journal entry requires at least two lines.']);
        }

        $debits = 0;
        $credits = 0;
        $normalized = [];
        foreach ($lines as $line) {
            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);
            if (($debit > 0) === ($credit > 0) || $debit < 0 || $credit < 0) {
                throw ValidationException::withMessages(['lines' => 'Each line must contain either a positive debit or a positive credit, never both.']);
            }
            $account = NexispChartOfAccount::query()
                ->where('organization_id', $organization->id)
                ->whereKey($line['account_id'])
                ->where('active', true)
                ->firstOrFail();
            $debits += $debit;
            $credits += $credit;
            $normalized[] = [
                'organization_id' => $organization->id,
                'account_id' => $account->id,
                'description' => $line['description'] ?? null,
                'debit' => number_format($debit / 100, 2, '.', ''),
                'credit' => number_format($credit / 100, 2, '.', ''),
            ];
        }

        if ($debits <= 0 || $debits !== $credits) {
            throw ValidationException::withMessages(['lines' => 'Journal entry must balance exactly: total debits must equal total credits.']);
        }

        return DB::transaction(function () use ($organization, $actor, $data, $normalized, $debits): NexispJournalEntry {
            $entry = NexispJournalEntry::create([
                'organization_id' => $organization->id,
                'entry_number' => $data['entry_number'],
                'status' => 'posted',
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'description' => $data['description'],
                'currency' => strtoupper($data['currency']),
                'entry_date' => $data['entry_date'],
                'total_debit' => number_format($debits / 100, 2, '.', ''),
                'total_credit' => number_format($debits / 100, 2, '.', ''),
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => $actor->id,
                'posted_at' => now(),
            ]);
            $entry->lines()->createMany($normalized);

            AuditLog::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'action' => 'nexisp.accounting.journal_entry_posted',
                'subject_type' => NexispJournalEntry::class,
                'subject_id' => (string) $entry->id,
                'metadata' => ['entry_number' => $entry->entry_number, 'total' => $entry->total_debit, 'currency' => $entry->currency],
            ]);

            return $entry->load('lines.account');
        });
    }

    public function issueReceipt(
        User $actor,
        Organization $organization,
        int $invoiceId,
        ?int $paymentTransactionId = null,
    ): NexispReceipt {
        Gate::forUser($actor)->authorize('manageBilling', $organization);

        return DB::transaction(function () use ($actor, $organization, $invoiceId, $paymentTransactionId): NexispReceipt {
            $invoice = NexispInvoice::query()
                ->where('organization_id', $organization->id)
                ->whereKey($invoiceId)
                ->with('customer')
                ->lockForUpdate()
                ->firstOrFail();

            $payment = null;
            if ($paymentTransactionId !== null) {
                $payment = PaymentTransaction::query()
                    ->where('organization_id', $organization->id)
                    ->whereKey($paymentTransactionId)
                    ->where('payable_type', NexispInvoice::class)
                    ->where('payable_id', $invoice->id)
                    ->whereNotNull('verified_at')
                    ->firstOrFail();

                if (bccomp((string) $payment->amount, (string) $invoice->amount, 2) !== 0
                    || strtoupper($payment->currency) !== strtoupper($invoice->currency)) {
                    throw ValidationException::withMessages(['payment' => 'Verified payment amount and currency must match the invoice before a receipt can be issued.']);
                }
            } elseif (! in_array($invoice->status, ['paid', 'partially_paid'], true) || $invoice->paid_at === null) {
                throw ValidationException::withMessages(['invoice' => 'A receipt can only be issued for an invoice with a recorded payment.']);
            }

            if ($payment !== null) {
                $existing = NexispReceipt::query()
                    ->where('organization_id', $organization->id)
                    ->where('invoice_id', $invoice->id)
                    ->where('payment_transaction_id', $payment->id)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $settings = NexispAccountingSetting::firstOrCreate(
                ['organization_id' => $organization->id],
                ['operating_mode' => 'native', 'base_currency' => 'KES'],
            );
            $number = $this->nextNumber($organization, 'receipt');
            $amount = $payment?->amount ?? $invoice->amount;

            $receipt = NexispReceipt::create([
                'organization_id' => $organization->id,
                'invoice_id' => $invoice->id,
                'payment_transaction_id' => $payment?->id,
                'receipt_number' => $number,
                'amount' => $amount,
                'currency' => $invoice->currency,
                'payment_method' => $payment?->provider,
                'payment_reference' => $payment?->provider_reference,
                'issued_at' => now(),
                'snapshot' => [
                    'invoice_number' => $invoice->invoice_number,
                    'customer_name' => $invoice->customer?->name,
                    'customer_number' => $invoice->customer?->customer_number,
                    'amount' => (string) $amount,
                    'currency' => $invoice->currency,
                ],
                'issued_by_user_id' => $actor->id,
            ]);

            AuditLog::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'action' => 'nexisp.accounting.receipt_issued',
                'subject_type' => NexispReceipt::class,
                'subject_id' => (string) $receipt->id,
                'metadata' => ['receipt_number' => $number, 'invoice_id' => $invoice->id],
            ]);

            return $receipt;
        });
    }

    private function nextNumber(Organization $organization, string $kind): string
    {
        $settings = NexispAccountingSetting::firstOrCreate(
            ['organization_id' => $organization->id],
            ['operating_mode' => 'native', 'base_currency' => 'KES'],
        );
        $prefix = match ($kind) {
            'receipt' => $settings->receipt_prefix,
            'expense' => $settings->expense_prefix,
            default => $settings->invoice_prefix,
        };
        $year = now($organization->timezone ?: 'UTC')->format('Y');
        $sequence = match ($kind) {
            'receipt' => NexispReceipt::where('organization_id', $organization->id)->whereYear('created_at', $year)->count() + 1,
            'expense' => NexispExpense::where('organization_id', $organization->id)->whereYear('created_at', $year)->count() + 1,
            default => NexispInvoice::where('organization_id', $organization->id)->whereYear('created_at', $year)->count() + 1,
        };

        return sprintf('%s-%s-%06d', $prefix, $year, $sequence);
    }

    private function toCents(string|int|float $amount): int
    {
        if (! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', (string) $amount)) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive amount with no more than two decimal places.']);
        }

        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        return $cents;
    }
}
