<?php

namespace App\Services\Nexisp;

use App\Models\NexbookInvoice;
use Illuminate\Validation\ValidationException;

class NexbookReceivablesAgingService
{
    /**
     * Return outstanding receivables grouped by customer and currency.
     * Currency totals are deliberately never combined.
     */
    public function report(int $organizationId, string $asOf): array
    {
        $timestamp = strtotime($asOf);
        if ($timestamp === false || date('Y-m-d', $timestamp) !== $asOf) {
            throw ValidationException::withMessages(['as_of' => 'Use a valid ISO date for the ageing report.']);
        }

        $buckets = ['current', '1_30_days', '31_60_days', '61_90_days', 'over_90_days'];
        $customers = [];

        $invoices = NexbookInvoice::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereDate('issue_date', '<=', $asOf)
            ->orderBy('customer_name')
            ->orderBy('currency')
            ->orderBy('due_date')
            ->get([
                'id', 'invoice_number', 'customer_name', 'currency',
                'issue_date', 'due_date', 'total', 'amount_paid',
            ]);

        foreach ($invoices as $invoice) {
            $outstanding = $this->minorUnits($invoice->total) - $this->minorUnits($invoice->amount_paid);
            if ($outstanding <= 0) {
                continue;
            }

            $dueDate = $invoice->due_date?->format('Y-m-d') ?? $invoice->issue_date->format('Y-m-d');
            $daysPastDue = max(0, (int) floor((strtotime($asOf) - strtotime($dueDate)) / 86400));
            $bucket = match (true) {
                $daysPastDue === 0 => 'current',
                $daysPastDue <= 30 => '1_30_days',
                $daysPastDue <= 60 => '31_60_days',
                $daysPastDue <= 90 => '61_90_days',
                default => 'over_90_days',
            };

            $key = mb_strtolower(trim($invoice->customer_name)) . '|' . $invoice->currency;
            $customers[$key] ??= [
                'customer_name' => $invoice->customer_name,
                'currency' => $invoice->currency,
                'invoice_count' => 0,
                'buckets_minor' => array_fill_keys($buckets, 0),
                'invoices' => [],
            ];

            $customers[$key]['invoice_count']++;
            $customers[$key]['buckets_minor'][$bucket] += $outstanding;
            $customers[$key]['invoices'][] = [
                'invoice_number' => $invoice->invoice_number,
                'issue_date' => $invoice->issue_date->format('Y-m-d'),
                'due_date' => $dueDate,
                'days_past_due' => $daysPastDue,
                'bucket' => $bucket,
                'outstanding' => $this->decimalAmount($outstanding),
            ];
        }

        $rows = [];
        $currencyTotals = [];
        foreach ($customers as $customer) {
            $total = array_sum($customer['buckets_minor']);
            $row = [
                'customer_name' => $customer['customer_name'],
                'currency' => $customer['currency'],
                'invoice_count' => $customer['invoice_count'],
            ];
            foreach ($buckets as $bucket) {
                $row[$bucket] = $this->decimalAmount($customer['buckets_minor'][$bucket]);
            }
            $row['total_outstanding'] = $this->decimalAmount($total);
            $row['invoices'] = $customer['invoices'];
            $rows[] = $row;

            $currency = $customer['currency'];
            $currencyTotals[$currency] ??= array_fill_keys($buckets, 0);
            foreach ($buckets as $bucket) {
                $currencyTotals[$currency][$bucket] += $customer['buckets_minor'][$bucket];
            }
        }

        ksort($currencyTotals);
        $totals = [];
        foreach ($currencyTotals as $currency => $values) {
            foreach ($values as $bucket => $amount) {
                $totals[$currency][$bucket] = $this->decimalAmount($amount);
            }
            $totals[$currency]['total_outstanding'] = $this->decimalAmount(array_sum($values));
        }

        return [
            'as_of' => $asOf,
            'customers' => $rows,
            'currency_totals' => $totals,
        ];
    }

    private function minorUnits(mixed $amount): int
    {
        $value = (string) $amount;
        if (!preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Invoice amounts must be valid non-negative decimal values.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function decimalAmount(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
