<?php

namespace App\Http\Controllers;

use App\Services\Nexisp\NexbookCsvExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class NexbookCreditRefundExportController extends Controller
{
    public function creditNotes(Request $request, NexbookCsvExportService $csv)
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::forUser($request->user())->authorize('view', $organization);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = DB::table('nexbook_credit_notes as credits')
            ->join('nexbook_invoices as invoices', function ($join) use ($organization) {
                $join->on('invoices.id', '=', 'credits.invoice_id')
                    ->where('invoices.organization_id', '=', (int) $organization->id);
            })
            ->where('credits.organization_id', (int) $organization->id)
            ->where('credits.status', 'issued')
            ->orderBy('credits.credit_date')
            ->orderBy('credits.credit_note_number');

        if (!empty($validated['from'])) {
            $query->whereDate('credits.credit_date', '>=', $validated['from']);
        }
        if (!empty($validated['to'])) {
            $query->whereDate('credits.credit_date', '<=', $validated['to']);
        }

        $rows = [];
        foreach ($query->get([
            'credits.credit_note_number',
            'credits.credit_date',
            'credits.status',
            'credits.reason',
            'credits.currency',
            'credits.subtotal',
            'credits.tax_total',
            'credits.total',
            'invoices.invoice_number',
            'invoices.customer_name',
        ]) as $credit) {
            $rows[] = [
                $csv->safeText($credit->credit_note_number),
                $credit->credit_date,
                $csv->safeText($credit->invoice_number),
                $csv->safeText($credit->customer_name),
                $csv->safeText($credit->reason),
                $credit->currency,
                $credit->subtotal,
                $credit->tax_total,
                $credit->total,
            ];
        }

        return $csv->download('nexbook-credit-notes-' . now()->format('Ymd-His') . '.csv', [
            'Credit note', 'Credit date', 'Original invoice', 'Customer',
            'Reason', 'Currency', 'Subtotal', 'Tax', 'Total',
        ], $rows);
    }

    public function refunds(Request $request, NexbookCsvExportService $csv)
    {
        $organization = $request->attributes->get('activeOrganization');
        Gate::forUser($request->user())->authorize('view', $organization);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'method' => ['nullable', 'in:cash,bank_transfer,mobile_money,card,cheque,other'],
        ]);

        $query = DB::table('nexbook_payment_refunds as refunds')
            ->join('nexbook_payments as payments', function ($join) use ($organization) {
                $join->on('payments.id', '=', 'refunds.payment_id')
                    ->where('payments.organization_id', '=', (int) $organization->id);
            })
            ->leftJoin('nexbook_payment_refund_allocations as allocations', 'allocations.refund_id', '=', 'refunds.id')
            ->leftJoin('nexbook_invoices as invoices', function ($join) use ($organization) {
                $join->on('invoices.id', '=', 'allocations.invoice_id')
                    ->where('invoices.organization_id', '=', (int) $organization->id);
            })
            ->where('refunds.organization_id', (int) $organization->id)
            ->where('refunds.status', 'posted')
            ->orderBy('refunds.refund_date')
            ->orderBy('refunds.refund_number');

        if (!empty($validated['from'])) {
            $query->whereDate('refunds.refund_date', '>=', $validated['from']);
        }
        if (!empty($validated['to'])) {
            $query->whereDate('refunds.refund_date', '<=', $validated['to']);
        }
        if (!empty($validated['method'])) {
            $query->where('refunds.method', $validated['method']);
        }

        $refunds = [];
        foreach ($query->get([
            'refunds.id',
            'refunds.refund_number',
            'refunds.refund_date',
            'refunds.currency',
            'refunds.method',
            'refunds.reference',
            'refunds.reason',
            'refunds.amount',
            'payments.receipt_number',
            'invoices.invoice_number',
        ]) as $item) {
            $id = (int) $item->id;
            $refunds[$id] ??= [
                $csv->safeText($item->refund_number),
                $item->refund_date,
                $csv->safeText($item->receipt_number),
                $item->currency,
                $csv->safeText($item->method),
                $csv->safeText($item->reference),
                $csv->safeText($item->reason),
                [],
                $item->amount,
            ];
            if ($item->invoice_number !== null) {
                $refunds[$id][7][$item->invoice_number] = $csv->safeText($item->invoice_number);
            }
        }

        $rows = [];
        foreach ($refunds as $refund) {
            $refund[7] = implode('; ', array_values($refund[7]));
            $rows[] = $refund;
        }

        return $csv->download('nexbook-refunds-' . now()->format('Ymd-His') . '.csv', [
            'Refund number', 'Refund date', 'Original receipt', 'Currency',
            'Method', 'Reference', 'Reason', 'Allocated invoices', 'Amount refunded',
        ], $rows);
    }
}
