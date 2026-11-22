<?php

namespace App\Http\Controllers;

use App\Services\Nexisp\NexbookCsvExportService;
use App\Services\Nexisp\NexbookCustomerStatementService;
use App\Services\Nexisp\NexbookReceivablesAgingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class NexbookExportController extends Controller
{
    public function customerStatement(
        Request $request,
        NexbookCustomerStatementService $statements,
        NexbookCsvExportService $csv,
    ) {
        $organization = $request->attributes->get('activeOrganization');
        Gate::forUser($request->user())->authorize('view', $organization);

        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'min:1', 'max:160'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $statement = $statements->forCustomer(
            (int) $organization->id,
            $validated['customer_name'],
            $validated['from'],
            $validated['to'],
        );

        $rows = [];
        foreach ($statement['currencies'] as $currency => $data) {
            $rows[] = [
                $validated['from'], 'Opening balance', '', '', $currency,
                '', '', $data['opening_balance'],
            ];
            foreach ($data['transactions'] as $transaction) {
                $rows[] = [
                    $transaction['date'],
                    $csv->safeText($transaction['type']),
                    $csv->safeText($transaction['reference']),
                    $csv->safeText($transaction['description']),
                    $currency,
                    $transaction['debit'],
                    $transaction['credit'],
                    $transaction['balance'],
                ];
            }
            $rows[] = [
                $validated['to'], 'Closing balance', '', '', $currency,
                $data['debits'], $data['credits'], $data['closing_balance'],
            ];
            $rows[] = ['', '', '', '', '', '', '', ''];
        }

        $filename = 'customer-statement-' . Str::slug($validated['customer_name']) . '-' . $validated['from'] . '-to-' . $validated['to'] . '.csv';

        return $csv->download($filename, [
            'Date', 'Transaction type', 'Reference', 'Description',
            'Currency', 'Debit', 'Credit', 'Running balance',
        ], $rows);
    }

    public function receivablesAging(
        Request $request,
        NexbookReceivablesAgingService $aging,
        NexbookCsvExportService $csv,
    ) {
        $organization = $request->attributes->get('activeOrganization');
        Gate::forUser($request->user())->authorize('view', $organization);

        $validated = $request->validate([
            'as_of' => ['required', 'date_format:Y-m-d'],
        ]);

        $report = $aging->report((int) $organization->id, $validated['as_of']);
        $rows = [];
        foreach ($report['customers'] as $customer) {
            $rows[] = [
                $csv->safeText($customer['customer_name']),
                $customer['currency'],
                $customer['invoice_count'],
                $customer['current'],
                $customer['1_30_days'],
                $customer['31_60_days'],
                $customer['61_90_days'],
                $customer['over_90_days'],
                $customer['total_outstanding'],
            ];
        }

        foreach ($report['currency_totals'] as $currency => $totals) {
            $rows[] = [
                'TOTAL', $currency, '',
                $totals['current'], $totals['1_30_days'], $totals['31_60_days'],
                $totals['61_90_days'], $totals['over_90_days'],
                $totals['total_outstanding'],
            ];
        }

        return $csv->download('receivables-ageing-' . $validated['as_of'] . '.csv', [
            'Customer', 'Currency', 'Invoice count', 'Current',
            '1–30 days', '31–60 days', '61–90 days', 'Over 90 days',
            'Total outstanding',
        ], $rows);
    }

    public function invoices(Request $request, NexbookCsvExportService $csv)
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::forUser($request->user())->authorize('view', $organization);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:draft,issued,partially_paid,paid'],
        ]);

        $credited = DB::table('nexbook_credit_notes')
            ->where('organization_id', (int) $organization->id)
            ->where('status', 'issued')
            ->select('invoice_id', DB::raw('SUM(total) as credited_total'))
            ->groupBy('invoice_id');

        $query = DB::table('nexbook_invoices as invoices')
            ->leftJoinSub($credited, 'credits', fn ($join) => $join->on('credits.invoice_id', '=', 'invoices.id'))
            ->where('invoices.organization_id', (int) $organization->id)
            ->orderBy('invoices.issue_date')
            ->orderBy('invoices.invoice_number');
        if (!empty($validated['from'])) $query->whereDate('invoices.issue_date', '>=', $validated['from']);
        if (!empty($validated['to'])) $query->whereDate('invoices.issue_date', '<=', $validated['to']);
        if (!empty($validated['status'])) $query->where('invoices.status', $validated['status']);

        $rows = [];
        foreach ($query->get([
            'invoices.invoice_number', 'invoices.status', 'invoices.customer_name', 'invoices.customer_email',
            'invoices.customer_phone', 'invoices.issue_date', 'invoices.due_date', 'invoices.currency',
            'invoices.subtotal', 'invoices.tax_total', 'invoices.total', 'invoices.amount_paid',
            DB::raw('COALESCE(credits.credited_total, 0) as credited_total'),
        ]) as $invoice) {
            $rows[] = [
                $csv->safeText($invoice->invoice_number),
                $csv->safeText($invoice->status),
                $csv->safeText($invoice->customer_name),
                $csv->safeText($invoice->customer_email),
                $csv->safeText($invoice->customer_phone),
                $invoice->issue_date,
                $invoice->due_date,
                $invoice->currency,
                $invoice->subtotal,
                $invoice->tax_total,
                $invoice->total,
                $invoice->amount_paid,
                $invoice->credited_total,
                $this->decimalMinor($this->minor($invoice->total) - $this->minor($invoice->amount_paid) - $this->minor($invoice->credited_total)),
            ];
        }

        return $csv->download('nexbook-invoices-' . now()->format('Ymd-His') . '.csv', [
            'Invoice number', 'Status', 'Customer', 'Email', 'Phone',
            'Issue date', 'Due date', 'Currency', 'Subtotal', 'Tax',
            'Total', 'Amount paid', 'Credited', 'Outstanding',
        ], $rows);
    }

    public function receipts(Request $request, NexbookCsvExportService $csv)
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::forUser($request->user())->authorize('view', $organization);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'method' => ['nullable', 'in:cash,bank_transfer,mobile_money,card,cheque,other'],
        ]);

        $query = DB::table('nexbook_payments as payments')
            ->leftJoin('nexbook_payment_allocations as allocations', 'allocations.payment_id', '=', 'payments.id')
            ->leftJoin('nexbook_invoices as invoices', function ($join) use ($organization) {
                $join->on('invoices.id', '=', 'allocations.invoice_id')
                    ->where('invoices.organization_id', '=', (int) $organization->id);
            })
            ->where('payments.organization_id', (int) $organization->id)
            ->where('payments.status', 'posted')
            ->orderBy('payments.payment_date')
            ->orderBy('payments.receipt_number');

        if (!empty($validated['from'])) $query->whereDate('payments.payment_date', '>=', $validated['from']);
        if (!empty($validated['to'])) $query->whereDate('payments.payment_date', '<=', $validated['to']);
        if (!empty($validated['method'])) $query->where('payments.method', $validated['method']);

        $receipts = [];
        foreach ($query->get([
            'payments.id', 'payments.receipt_number', 'payments.payment_date',
            'payments.currency', 'payments.method', 'payments.reference',
            'payments.amount', 'payments.allocated_amount', 'invoices.invoice_number',
        ]) as $item) {
            $id = (int) $item->id;
            $receipts[$id] ??= [
                $csv->safeText($item->receipt_number),
                $item->payment_date,
                $item->currency,
                $csv->safeText($item->method),
                $csv->safeText($item->reference),
                [],
                $item->amount,
                $item->allocated_amount,
            ];
            if ($item->invoice_number !== null) {
                $receipts[$id][5][$item->invoice_number] = $csv->safeText($item->invoice_number);
            }
        }

        $rows = [];
        foreach ($receipts as $receipt) {
            $receipt[5] = implode('; ', array_values($receipt[5]));
            $rows[] = $receipt;
        }

        return $csv->download('nexbook-receipts-' . now()->format('Ymd-His') . '.csv', [
            'Receipt number', 'Payment date', 'Currency', 'Method',
            'Reference', 'Allocated invoices', 'Amount received', 'Amount allocated',
        ], $rows);
    }


    private function minor(mixed $amount): int
    {
        $value = (string) $amount;
        if (!preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,2})?$/', $value)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['amount' => 'Exported monetary values must be valid decimals.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function decimalMinor(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

}
