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

    public function createInvoice(User $actor, Organization $organization, array $data): NexispInvoice
    {
        Gate::forUser($actor)->authorize('manageBilling', $organization);

        return DB::transaction(function () use ($actor, $organization, $data): NexispInvoice {
            $settings = NexispAccountingSetting::firstOrCreate(
                ['organization_id' => $organization->id],
                ['operating_mode' => 'native', 'base_currency' => 'KES'],
            );
            if (! $settings->native_ledger_enabled) {
                throw ValidationException::withMessages(['ledger' => 'The NexISP native ledger must remain enabled as the source of truth.']);
            }

            $customer = NexispCustomer::query()
                ->where('organization_id', $organization->id)
                ->whereKey($data['customer_id'])
                ->where('status', 'active')
                ->firstOrFail();

            if (! empty($data['subscription_id'])) {\n                $subscription = NexispSubscription::query()\n                    ->where('organization_id', $organization->id)\n                    ->whereKey($data['subscription_id'])\n                    ->firstOrFail();\n                if (($subscription->customer_id !== null && (int) $subscription->customer_id !== (int) $customer->id)\n                    || ($subscription->customer_id === null && (int) $subscription->user_id !== (int) ($customer->user_id ?? 0))) {\n                    throw ValidationException::withMessages(['subscription_id' => 'The selected subscription does not belong to this customer.']);\n                }\n            }\n\n            if (! empty($data['idempotency_key'])) {
                $existing = NexispInvoice::query()
                    ->where('organization_id', $organization->id)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->first();
                if ($existing) {
                    return $existing->load('items');
                }
            }

            $receivable = NexispChartOfAccount::query()
                ->where('organization_id', $organization->id)->where('code', '1100')->where('active', true)->first();
            $revenue = NexispChartOfAccount::query()
                ->where('organization_id', $organization->id)->where('code', '4000')->where('active', true)->first();
            $taxPayable = NexispChartOfAccount::query()
                ->where('organization_id', $organization->id)->where('code', '2100')->where('active', true)->first();

            if (! $receivable || ! $revenue || ! $taxPayable) {
                throw ValidationException::withMessages(['ledger' => 'Initialize NexISP Books and its default chart of accounts before issuing invoices.']);
            }

            $subtotalCents = 0;
            $taxCents = 0;
            $lineSnapshots = [];
            foreach ($data['items'] as $line) {
                $quantity = (int) $line['quantity'];
                $unitCents = $this->toCents((string) $line['unit_price']);
                $grossCents = $quantity * $unitCents;
                $rateBasisPoints = 0;
                $taxInclusive = false;

                if (! empty($line['tax_rate_id'])) {
                    if (! $settings->tax_enabled) {
                        throw ValidationException::withMessages(['tax_rate_id' => 'Tax features are disabled for this ISP.']);
                    }
                    $taxRate = \App\Models\NexispTaxRate::query()
                        ->where('organization_id', $organization->id)
                        ->whereKey($line['tax_rate_id'])
                        ->where('active', true)
                        ->firstOrFail();
                    $rateBasisPoints = (int) round(((float) $taxRate->rate_percent) * 100);
                    $taxInclusive = (bool) $taxRate->inclusive;
                }

                $lineTax = $taxInclusive
                    ? (int) round(($grossCents * $rateBasisPoints) / (10000 + $rateBasisPoints))
                    : (int) round(($grossCents * $rateBasisPoints) / 10000);
                $lineSubtotal = $taxInclusive ? $grossCents - $lineTax : $grossCents;
                $lineTotal = $taxInclusive ? $grossCents : $grossCents + $lineTax;
                $subtotalCents += $lineSubtotal;
                $taxCents += $lineTax;
                $lineSnapshots[] = [
                    'description' => $line['description'],
                    'quantity' => $quantity,
                    'unit_price' => number_format($unitCents / 100, 2, '.', ''),
                    'tax_rate_percent' => number_format($rateBasisPoints / 100, 4, '.', ''),
                    'line_subtotal' => number_format($lineSubtotal / 100, 2, '.', ''),
                    'tax_amount' => number_format($lineTax / 100, 2, '.', ''),
                    'line_total' => number_format($lineTotal / 100, 2, '.', ''),
                ];
            }

            if ($subtotalCents <= 0) {
                throw ValidationException::withMessages(['items' => 'Invoice total must be greater than zero.']);
            }

            $totalCents = $subtotalCents + $taxCents;
            $currency = strtoupper($data['currency'] ?? $settings->base_currency);
            $invoiceNumber = $this->nextNumber($organization, 'invoice');

            $invoice = NexispInvoice::create([
                'organization_id' => $organization->id,
                'customer_id' => $customer->id,
                'subscription_id' => $data['subscription_id'] ?? null,
                'invoice_number' => $invoiceNumber,
                'status' => 'open',
                'amount' => number_format($totalCents / 100, 2, '.', ''),
                'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
                'tax_amount' => number_format($taxCents / 100, 2, '.', ''),
                'currency' => $currency,
                'issued_at' => $data['issued_at'] ?? now(),
                'due_at' => $data['due_at'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'notes' => $data['notes'] ?? null,
                'document_snapshot' => [
                    'customer_name' => $customer->name,
                    'customer_number' => $customer->customer_number,
                    'items' => $lineSnapshots,
                ],
            ]);

            $invoice->items()->createMany(array_map(
                fn (array $line): array => ['organization_id' => $organization->id] + $line,
                $lineSnapshots,
            ));

            $journalLines = [
                ['account_id' => $receivable->id, 'debit' => $totalCents, 'credit' => 0, 'description' => 'Accounts receivable '.$invoiceNumber],
                ['account_id' => $revenue->id, 'debit' => 0, 'credit' => $subtotalCents, 'description' => 'Subscription and service revenue '.$invoiceNumber],
            ];
            if ($taxCents > 0) {
                $journalLines[] = ['account_id' => $taxPayable->id, 'debit' => 0, 'credit' => $taxCents, 'description' => 'Tax payable '.$invoiceNumber];
            }

            $entry = $this->postJournal($organization, $actor, [
                'entry_number' => 'JE-'.strtoupper(bin2hex(random_bytes(8))),
                'description' => 'Invoice issued '.$invoiceNumber,
                'currency' => $currency,
                'entry_date' => now($organization->timezone ?: 'UTC')->toDateString(),
                'source_type' => NexispInvoice::class,
                'source_id' => $invoice->id,
                'lines' => $journalLines,
            ]);

            AuditLog::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'action' => 'nexisp.accounting.invoice_issued',
                'subject_type' => NexispInvoice::class,
                'subject_id' => (string) $invoice->id,
                'metadata' => ['invoice_number' => $invoiceNumber, 'journal_entry_id' => $entry->id, 'amount' => $invoice->amount, 'currency' => $currency],
            ]);

            return $invoice->load('items');
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
                'entry_number' => 'JE-'.strtoupper(bin2hex(random_bytes(8))),
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

                if ($this->toCents((string) $payment->amount) !== $this->toCents((string) $invoice->amount)
                    || strtoupper($payment->currency) !== strtoupper($invoice->currency)) {
                    throw ValidationException::withMessages(['payment' => 'Verified payment amount and currency must match the invoice before a receipt can be issued.']);
                }
            } elseif (! in_array($invoice->status, ['paid', 'partially_paid'], true) || $invoice->paid_at === null) {
                throw ValidationException::withMessages(['invoice' => 'A receipt can only be issued for an invoice with a recorded payment.']);
            }

            $existingReceipt = NexispReceipt::query()
                ->where('organization_id', $organization->id)
                ->where('invoice_id', $invoice->id)
                ->when(
                    $payment !== null,
                    fn ($query) => $query->where('payment_transaction_id', $payment->id),
                    fn ($query) => $query->whereNull('payment_transaction_id'),
                )
                ->first();
            if ($existingReceipt) {
                return $existingReceipt;
            }

            if ($payment !== null) {
                $alreadyPosted = NexispJournalEntry::query()
                    ->where('organization_id', $organization->id)
                    ->where('source_type', PaymentTransaction::class)
                    ->where('source_id', $payment->id)
                    ->where('status', 'posted')
                    ->exists();

                if (! $alreadyPosted) {
                    $cashCode = str_contains(strtolower($payment->provider), 'cash') ? '1000' : '1010';
                    $cashAccount = NexispChartOfAccount::query()
                        ->where('organization_id', $organization->id)->where('code', $cashCode)->where('active', true)->first();
                    $receivable = NexispChartOfAccount::query()
                        ->where('organization_id', $organization->id)->where('code', '1100')->where('active', true)->first();

                    if (! $cashAccount || ! $receivable) {
                        throw ValidationException::withMessages(['ledger' => 'Initialize the cash/bank and receivables accounts before issuing a receipt.']);
                    }

                    $paymentCents = $this->toCents((string) $payment->amount);
                    $this->postJournal($organization, $actor, [
                        'entry_number' => 'JE-'.strtoupper(bin2hex(random_bytes(8))),
                        'description' => 'Payment received for '.$invoice->invoice_number,
                        'currency' => $payment->currency,
                        'entry_date' => now($organization->timezone ?: 'UTC')->toDateString(),
                        'source_type' => PaymentTransaction::class,
                        'source_id' => $payment->id,
                        'lines' => [
                            ['account_id' => $cashAccount->id, 'debit' => $paymentCents, 'credit' => 0, 'description' => 'Verified payment received'],
                            ['account_id' => $receivable->id, 'debit' => 0, 'credit' => $paymentCents, 'description' => 'Settle invoice '.$invoice->invoice_number],
                        ],
                    ]);
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

        DB::table('nexisp_document_sequences')->insertOrIgnore([
            'organization_id' => $organization->id,
            'document_type' => $kind,
            'period' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('nexisp_document_sequences')
            ->where('organization_id', $organization->id)
            ->where('document_type', $kind)
            ->where('period', $year)
            ->lockForUpdate()
            ->first();

        $number = (int) $sequence->next_number;
        DB::table('nexisp_document_sequences')
            ->where('id', $sequence->id)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return sprintf('%s-%s-%06d', $prefix, $year, $number);
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
