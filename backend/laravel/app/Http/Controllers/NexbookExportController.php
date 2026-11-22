<?php

namespace App\Http\Controllers;

use App\Services\Nexisp\NexbookCsvExportService;
use App\Services\Nexisp\NexbookCustomerStatementService;
use App\Services\Nexisp\NexbookReceivablesAgingService;
use Illuminate\Http\Request;
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
            'status' => ['nullable', 'in:draft,issued,partially_paid,paid,void'],
        ]);

        $query = DB::table('nexbook_invoices')
            ->where('organization_id', (int) $organization->id)
            ->orderBy('issue_date')
            ->orderBy('invoice_number');
        if (!empty($validated['from'])) $query->whereDate('issue_date', '>=', $validated['from']);
        if (!empty($validated['to'])) $query->whereDate('issue_date', '<=', $validated['to']);
        if (!empty($validated['status'])) $query->where('status', $validated['status']);

        $rows = [];
        foreach ($query->get([
            'invoice_number', 'status', 'customer_name', 'customer_email',
            'customer_phone', 'issue_date', 'due_date', 'currency',
            'subtotal', 'tax_total', 'total', 'amount_paid',
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
            ];
        }

        return $csv->download('nexbook-invoices-' . now()->format('Ymd-His') . '.csv', [
            'Invoice number', 'Status', 'Customer', 'Email', 'Phone',
            'Issue date', 'Due date', 'Currency', 'Subtotal', 'Tax',
            'Total', 'Amount paid',
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
            ->groupBy(
                'payments.id', 'payments.receipt_number', 'payments.payment_date',
                'payments.currency', 'payments.method', 'payments.reference',
                'payments.amount', 'payments.allocated_amount'
            )
            ->orderBy('payments.payment_date')
            ->orderBy('payments.receipt_number');

        if (!empty($validated['from'])) $query->whereDate('payments.payment_date', '>=', $validated['from']);
        if (!empty($validated['to'])) $query->whereDate('payments.payment_date', '<=', $validated['to']);
        if (!empty($validated['method'])) $query->where('payments.method', $validated['method']);

        $rows = [];
        foreach ($query->get([
            'payments.receipt_number', 'payments.payment_date', 'payments.currency',
            'payments.method', 'payments.reference', 'payments.amount',
            'payments.allocated_amount',
            DB::raw("GROUP_CONCAT(DISTINCT invoices.invoice_number) as invoice_numbers"),
        ]) as $receipt) {
            $rows[] = [
                $csv->safeText($receipt->receipt_number),
                $receipt->payment_date,
                $receipt->currency,
                $csv->safeText($receipt->method),
                $csv->safeText($receipt->reference),
                $csv->safeText($receipt->invoice_numbers),
                $receipt->amount,
                $receipt->allocated_amount,
            ];
        }

        return $csv->download('nexbook-receipts-' . now()->format('Ymd-His') . '.csv', [
            'Receipt number', 'Payment date', 'Currency', 'Method',
            'Reference', 'Allocated invoices', 'Amount received', 'Amount allocated',
        ], $rows);
    }

}
