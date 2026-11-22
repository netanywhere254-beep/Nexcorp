<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\NexispAccountingSetting;
use App\Models\NexispChartOfAccount;
use App\Models\NexispExpense;
use App\Models\NexispInvoice;
use App\Models\NexispJournalEntry;
use App\Models\NexispReceipt;
use App\Models\Organization;
use App\Services\NexispAccountingService;
use App\Services\NexispDocumentExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NexispBooksController
{
    public function settings(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $settings = NexispAccountingSetting::firstOrCreate(
            ['organization_id' => $organization->id],
            ['operating_mode' => 'native', 'base_currency' => 'KES'],
        );

        return response()->json(['data' => $settings]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $data = $request->validate([
            'operating_mode' => ['sometimes', 'in:native,hybrid,external_mirror'],
            'base_currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'fiscal_year_start' => ['sometimes', 'date_format:m-d'],
            'invoice_prefix' => ['sometimes', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
            'receipt_prefix' => ['sometimes', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
            'expense_prefix' => ['sometimes', 'string', 'max:16', 'regex:/^[A-Za-z0-9_-]+$/'],
            'expenses_enabled' => ['sometimes', 'boolean'],
            'tax_enabled' => ['sometimes', 'boolean'],
            'bank_reconciliation_enabled' => ['sometimes', 'boolean'],
            'budgets_enabled' => ['sometimes', 'boolean'],
            'recurring_invoices_enabled' => ['sometimes', 'boolean'],
            'external_sync_enabled' => ['sometimes', 'boolean'],
            'feature_preferences' => ['sometimes', 'array', 'max:40'],
        ]);

        $settings = NexispAccountingSetting::updateOrCreate(
            ['organization_id' => $organization->id],
            array_merge($data, ['native_ledger_enabled' => true]),
        );

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'nexisp.accounting.settings_updated',
            'subject_type' => NexispAccountingSetting::class,
            'subject_id' => (string) $settings->id,
            'metadata' => ['operating_mode' => $settings->operating_mode],
        ]);

        return response()->json(['data' => $settings->fresh()]);
    }

    public function bootstrap(Request $request, NexispAccountingService $accounting): JsonResponse
    {
        $organization = $this->organization($request);
        $created = $accounting->bootstrap($organization, $request->user());

        return response()->json([
            'data' => [
                'default_accounts_created' => $created,
                'ledger' => 'ready',
            ],
        ], 201);
    }

    public function accounts(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);

        $accounts = NexispChartOfAccount::query()
            ->where('organization_id', $organization->id)
            ->with('parent:id,code,name')
            ->orderBy('code')
            ->paginate(100);

        return response()->json([
            'data' => $accounts->items(),
            'meta' => ['current_page' => $accounts->currentPage(), 'last_page' => $accounts->lastPage()],
        ]);
    }

    public function journalEntries(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $entries = NexispJournalEntry::query()
            ->where('organization_id', $organization->id)
            ->with('lines.account:id,code,name')
            ->latest('entry_date')
            ->paginate(30);

        return response()->json([
            'data' => $entries->items(),
            'meta' => ['current_page' => $entries->currentPage(), 'last_page' => $entries->lastPage()],
        ]);
    }

    public function postJournal(Request $request, NexispAccountingService $accounting): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $data = $request->validate([
            'entry_number' => ['required', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'entry_date' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);

        foreach ($data['lines'] as &$line) {
            $line['debit'] = $this->toCents((string) ($line['debit'] ?? '0'));
            $line['credit'] = $this->toCents((string) ($line['credit'] ?? '0'));
        }
        unset($line);

        $entry = $accounting->postJournal($organization, $request->user(), $data);

        return response()->json(['data' => $entry], 201);
    }

    public function createExpense(Request $request, NexispAccountingService $accounting): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $data = $request->validate([
            'expense_account_id' => ['required', 'integer'],
            'payment_account_id' => ['required', 'integer', 'different:expense_account_id'],
            'vendor_name' => ['nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expense_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        return response()->json([
            'data' => $accounting->createExpense($request->user(), $organization, $data),
        ], 201);
    }

    public function expenses(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $expenses = NexispExpense::query()
            ->where('organization_id', $organization->id)
            ->with('account:id,code,name')
            ->latest('expense_date')
            ->paginate(30);

        return response()->json([
            'data' => $expenses->items(),
            'meta' => ['current_page' => $expenses->currentPage(), 'last_page' => $expenses->lastPage()],
        ]);
    }

    public function issueReceipt(Request $request, int $invoiceId, NexispAccountingService $accounting): JsonResponse
    {
        $organization = $this->organization($request);
        $data = $request->validate(['payment_transaction_id' => ['nullable', 'integer']]);
        $receipt = $accounting->issueReceipt(
            $request->user(),
            $organization,
            $invoiceId,
            isset($data['payment_transaction_id']) ? (int) $data['payment_transaction_id'] : null,
        );

        return response()->json(['data' => $receipt], 201);
    }

    public function exportInvoice(
        Request $request,
        int $invoiceId,
        string $format,
        NexispDocumentExportService $exporter,
    ): StreamedResponse {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $invoice = NexispInvoice::query()
            ->where('organization_id', $organization->id)
            ->with('customer')
            ->whereKey($invoiceId)
            ->firstOrFail();

        $rows = [
            ['Field', 'Value'],
            ['Invoice number', $invoice->invoice_number],
            ['Customer', $invoice->customer?->name ?? ''],
            ['Customer number', $invoice->customer?->customer_number ?? ''],
            ['Status', $invoice->status],
            ['Amount', (string) $invoice->amount],
            ['Currency', $invoice->currency],
            ['Issued at', $invoice->issued_at?->toIso8601String() ?? ''],
            ['Due at', $invoice->due_at?->toIso8601String() ?? ''],
            ['Paid at', $invoice->paid_at?->toIso8601String() ?? ''],
        ];

        return $this->download($exporter, $format, 'Invoice '.$invoice->invoice_number, $rows);
    }

    public function exportReceipt(
        Request $request,
        int $receiptId,
        string $format,
        NexispDocumentExportService $exporter,
    ): StreamedResponse {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $receipt = NexispReceipt::query()
            ->where('organization_id', $organization->id)
            ->whereKey($receiptId)
            ->firstOrFail();

        $snapshot = $receipt->snapshot ?? [];
        $rows = [
            ['Field', 'Value'],
            ['Receipt number', $receipt->receipt_number],
            ['Invoice number', $snapshot['invoice_number'] ?? ''],
            ['Customer', $snapshot['customer_name'] ?? ''],
            ['Customer number', $snapshot['customer_number'] ?? ''],
            ['Amount received', (string) $receipt->amount],
            ['Currency', $receipt->currency],
            ['Payment method', $receipt->payment_method ?? ''],
            ['Payment reference', $receipt->payment_reference ?? ''],
            ['Issued at', $receipt->issued_at?->toIso8601String() ?? ''],
        ];

        return $this->download($exporter, $format, 'Receipt '.$receipt->receipt_number, $rows);
    }

    public function overview(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);

        $revenue = (float) DB::table('nexisp_journal_entry_lines as lines')
            ->join('nexisp_chart_of_accounts as accounts', function ($join) {
                $join->on('accounts.id', '=', 'lines.account_id')
                    ->on('accounts.organization_id', '=', 'lines.organization_id');
            })
            ->join('nexisp_journal_entries as entries', function ($join) {
                $join->on('entries.id', '=', 'lines.journal_entry_id')
                    ->on('entries.organization_id', '=', 'lines.organization_id');
            })
            ->where('lines.organization_id', $organization->id)
            ->where('entries.status', 'posted')
            ->where('accounts.account_type', 'revenue')
            ->selectRaw('COALESCE(SUM(lines.credit - lines.debit), 0) as total')
            ->value('total');

        $expensesTotal = (float) DB::table('nexisp_journal_entry_lines as lines')
            ->join('nexisp_chart_of_accounts as accounts', function ($join) {
                $join->on('accounts.id', '=', 'lines.account_id')
                    ->on('accounts.organization_id', '=', 'lines.organization_id');
            })
            ->join('nexisp_journal_entries as entries', function ($join) {
                $join->on('entries.id', '=', 'lines.journal_entry_id')
                    ->on('entries.organization_id', '=', 'lines.organization_id');
            })
            ->where('lines.organization_id', $organization->id)
            ->where('entries.status', 'posted')
            ->where('accounts.account_type', 'expense')
            ->selectRaw('COALESCE(SUM(lines.debit - lines.credit), 0) as total')
            ->value('total');

        return response()->json([
            'data' => [
                'currency' => NexispAccountingSetting::where('organization_id', $organization->id)->value('base_currency') ?? 'KES',
                'revenue' => number_format($revenue, 2, '.', ''),
                'expenses' => number_format($expensesTotal, 2, '.', ''),
                'net_result' => number_format($revenue - $expensesTotal, 2, '.', ''),
                'open_invoices' => NexispInvoice::where('organization_id', $organization->id)->whereIn('status', ['open', 'overdue'])->count(),
                'receipts_issued' => NexispReceipt::where('organization_id', $organization->id)->count(),
                'posted_journal_entries' => NexispJournalEntry::where('organization_id', $organization->id)->where('status', 'posted')->count(),
            ],
        ]);
    }

    private function download(
        NexispDocumentExportService $exporter,
        string $format,
        string $title,
        array $rows,
    ): StreamedResponse {
        $document = $exporter->render($format, $title, $rows);
        $filename = str_replace(['/', '\\'], '-', $title).'.'.$document['extension'];

        return response()->streamDownload(
            static function () use ($document): void {
                echo $document['content'];
            },
            $filename,
            ['Content-Type' => $document['mime']],
        );
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('activeOrganization');
        abort_unless($organization instanceof Organization, 409, 'Select an active organization first.');

        return $organization;
    }

    private function toCents(string $amount): int
    {
        if (! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages(['lines' => 'Enter valid amounts with no more than two decimal places.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
