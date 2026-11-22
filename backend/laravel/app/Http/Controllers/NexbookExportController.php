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
}
