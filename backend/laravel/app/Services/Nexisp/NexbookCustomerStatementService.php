<?php

namespace App\Services\Nexisp;

use App\Models\NexbookInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NexbookCustomerStatementService
{
    /**
     * Build a customer statement with independent running balances per currency.
     * The opening balance includes all posted activity before the requested period.
     *
     * @return array{customer_name:string,from:string,to:string,currencies:array<string,array<string,mixed>>}
     */
    public function forCustomer(
        int $organizationId,
        string $customerName,
        string $from,
        string $to,
    ): array {
        $customerName = trim($customerName);
        if ($customerName === '' || mb_strlen($customerName) > 160) {
            throw ValidationException::withMessages(['customer_name' => 'A valid customer name is required.']);
        }
        if (!$this->validDate($from) || !$this->validDate($to) || $to < $from) {
            throw ValidationException::withMessages(['period' => 'Statement dates must be valid ISO dates and the end date cannot precede the start date.']);
        }

        $invoices = NexbookInvoice::query()
            ->where('organization_id', $organizationId)
            ->whereRaw('LOWER(TRIM(customer_name)) = ?', [mb_strtolower($customerName)])
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereDate('issue_date', '<=', $to)
            ->orderBy('issue_date')
            ->orderBy('id')
            ->get(['id', 'invoice_number', 'issue_date', 'due_date', 'currency', 'total']);

        $invoiceIds = $invoices->pluck('id')->all();
        $events = [];

        foreach ($invoices as $invoice) {
            $events[] = [
                'date' => $invoice->issue_date->format('Y-m-d'),
                'sort' => 0,
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'description' => 'Invoice issued',
                'currency' => $invoice->currency,
                'debit_minor' => $this->minorUnits($invoice->total),
                'credit_minor' => 0,
                'due_date' => $invoice->due_date?->format('Y-m-d'),
            ];
        }

        if ($invoiceIds !== []) {
            $receipts = DB::table('nexbook_payment_allocations as allocations')
                ->join('nexbook_payments as payments', 'payments.id', '=', 'allocations.payment_id')
                ->join('nexbook_invoices as invoices', 'invoices.id', '=', 'allocations.invoice_id')
                ->where('payments.organization_id', $organizationId)
                ->where('invoices.organization_id', $organizationId)
                ->whereIn('allocations.invoice_id', $invoiceIds)
                ->where('payments.status', 'posted')
                ->whereDate('payments.payment_date', '<=', $to)
                ->orderBy('payments.payment_date')
                ->orderBy('payments.id')
                ->get([
                    'payments.payment_date',
                    'payments.receipt_number',
                    'payments.currency',
                    'allocations.amount',
                    'invoices.customer_name',
                ]);

            foreach ($receipts as $receipt) {
                if (mb_strtolower(trim($receipt->customer_name)) !== mb_strtolower($customerName)) {
                    continue;
                }
                $events[] = [
                    'date' => substr((string) $receipt->payment_date, 0, 10),
                    'sort' => 1,
                    'type' => 'receipt',
                    'reference' => $receipt->receipt_number,
                    'description' => 'Payment received',
                    'currency' => $receipt->currency,
                    'debit_minor' => 0,
                    'credit_minor' => $this->minorUnits($receipt->amount),
                    'due_date' => null,
                ];
            }
        }

        usort($events, fn (array $a, array $b) => [$a['date'], $a['sort'], $a['reference']] <=> [$b['date'], $b['sort'], $b['reference']]);

        $currencies = [];
        foreach ($events as $event) {
            $currency = $event['currency'];
            $currencies[$currency] ??= [
                'opening_balance' => '0.00',
                'debits' => '0.00',
                'credits' => '0.00',
                'closing_balance' => '0.00',
                'transactions' => [],
            ];

            if ($event['date'] < $from) {
                $balance = $this->minorUnits($currencies[$currency]['opening_balance']);
                $balance += $event['debit_minor'] - $event['credit_minor'];
                $currencies[$currency]['opening_balance'] = $this->decimalAmount($balance);
                continue;
            }

            if ($event['date'] > $to) {
                continue;
            }

            $debits = $this->minorUnits($currencies[$currency]['debits']) + $event['debit_minor'];
            $credits = $this->minorUnits($currencies[$currency]['credits']) + $event['credit_minor'];
            $currencies[$currency]['debits'] = $this->decimalAmount($debits);
            $currencies[$currency]['credits'] = $this->decimalAmount($credits);

            $balance = $this->minorUnits($currencies[$currency]['closing_balance']);
            $balance += $event['debit_minor'] - $event['credit_minor'];
            $currencies[$currency]['closing_balance'] = $this->decimalAmount($balance);

            $currencies[$currency]['transactions'][] = [
                'date' => $event['date'],
                'type' => $event['type'],
                'reference' => $event['reference'],
                'description' => $event['description'],
                'due_date' => $event['due_date'],
                'debit' => $this->decimalAmount($event['debit_minor']),
                'credit' => $this->decimalAmount($event['credit_minor']),
                'balance' => $this->decimalAmount($balance),
            ];
        }

        foreach ($currencies as $currency => $statement) {
            $opening = $this->minorUnits($statement['opening_balance']);
            $closing = $opening + $this->minorUnits($statement['debits']) - $this->minorUnits($statement['credits']);
            $currencies[$currency]['closing_balance'] = $this->decimalAmount($closing);
        }

        ksort($currencies);

        return [
            'customer_name' => $customerName,
            'from' => $from,
            'to' => $to,
            'currencies' => $currencies,
        ];
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
            throw ValidationException::withMessages(['amount' => 'Statement amounts must be valid non-negative decimal values.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function decimalAmount(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
