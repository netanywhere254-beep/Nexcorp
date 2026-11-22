<?php

namespace App\Http\Controllers;

use App\Models\AccountingConnection;
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

        return response()->json([
            'data' => $settings,
            'feature_catalog' => [
                ['key' => 'native_ledger', 'available' => true, 'required' => true],
                ['key' => 'expenses', 'available' => true, 'enabled' => $settings->expenses_enabled],
                ['key' => 'tax', 'available' => true, 'enabled' => $settings->tax_enabled],
                ['key' => 'bank_reconciliation', 'available' => false, 'enabled' => false, 'status' => 'planned'],
                ['key' => 'budgets', 'available' => false, 'enabled' => false, 'status' => 'planned'],
                ['key' => 'recurring_invoices', 'available' => false, 'enabled' => false, 'status' => 'planned'],
                ['key' => 'external_sync', 'available' => false, 'enabled' => false, 'status' => 'provider_authorization_pending'],
            ],
        ]);
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
            'external_sync_enabled' => ['sometimes', 'boolean'],
            'feature_preferences' => ['sometimes', 'array', 'max:40'],
        ]);

        if ((in_array($data['operating_mode'] ?? null, ['hybrid', 'external_mirror'], true)
                || ($data['external_sync_enabled'] ?? false) === true)
            && ! AccountingConnection::query()->where('organization_id', $organization->id)->where('status', 'connected')->exists()) {
            throw ValidationException::withMessages(['operating_mode' => 'Connect an accounting provider before switching to hybrid or external mirror mode.']);
        }

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

        $baseCurrency = strtoupper(NexispAccountingSetting::where('organization_id', $organization->id)->value('base_currency') ?? 'KES');
        if (strtoupper($data['currency']) !== $baseCurrency) {
            throw ValidationException::withMessages(['currency' => 'Manual journal entries must use the ISP base currency until multi-currency accounting is enabled.']);
        }

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

    public function invoices(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $invoices = NexispInvoice::query()
            ->where('organization_id', $organization->id)
            ->with(['customer:id,name,customer_number', 'items'])
            ->latest('issued_at')
            ->paginate(30);

        return response()->json([
            'data' => $invoices->items(),
            'meta' => ['current_page' => $invoices->currentPage(), 'last_page' => $invoices->lastPage()],
        ]);
    }

    public function createInvoice(Request $request, NexispAccountingService $accounting): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'subscription_id' => ['nullable', 'integer'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'issued_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'items.*.tax_rate_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['due_at']) && strtotime($data['due_at']) < strtotime($data['issued_at'] ?? now()->toDateTimeString())) {
            throw ValidationException::withMessages(['due_at' => 'The due date cannot be earlier than the invoice issue date.']);
        }

        $invoice = $accounting->createInvoice($request->user(), $organization, $data);

        return response()->json(['data' => $invoice], 201);
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
            ->with(['customer', 'items'])
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
        foreach ($invoice->items as $index => $item) {
            $rows[] = ['Item '.($index + 1), $item->description];
            $rows[] = ['Quantity', (string) $item->quantity];
            $rows[] = ['Unit price', (string) $item->unit_price];
            $rows[] = ['Tax', (string) $item->tax_amount];
            $rows[] = ['Line total', (string) $item->line_total];
        }

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

    public function createAccount(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'account_type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'normal_balance' => ['required', 'in:debit,credit'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        $expected = in_array($data['account_type'], ['asset', 'expense'], true) ? 'debit' : 'credit';
        if ($data['normal_balance'] !== $expected) {
            throw ValidationException::withMessages(['normal_balance' => 'Normal balance must match the selected account type.']);
        }
        if (! empty($data['parent_id'])) {
            NexispChartOfAccount::query()
                ->where('organization_id', $organization->id)
                ->whereKey($data['parent_id'])
                ->firstOrFail();
        }

        $account = NexispChartOfAccount::create([
            'organization_id' => $organization->id,
            'code' => $data['code'],
            'name' => $data['name'],
            'account_type' => $data['account_type'],
            'normal_balance' => $data['normal_balance'],
            'parent_id' => $data['parent_id'] ?? null,
            'system_account' => false,
            'active' => true,
        ]);

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'nexisp.accounting.account_created',
            'subject_type' => NexispChartOfAccount::class,
            'subject_id' => (string) $account->id,
            'metadata' => ['code' => $account->code, 'account_type' => $account->account_type],
        ]);

        return response()->json(['data' => $account], 201);
    }

    public function taxRates(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        return response()->json([
            'data' => \App\Models\NexispTaxRate::query()
                ->where('organization_id', $organization->id)
                ->orderBy('code')
                ->get(),
        ]);
    }

    public function createTaxRate(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'name' => ['required', 'string', 'max:100'],
            'rate_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'inclusive' => ['sometimes', 'boolean'],
        ]);

        $tax = \App\Models\NexispTaxRate::create([
            'organization_id' => $organization->id,
            'code' => strtoupper($data['code']),
            'name' => $data['name'],
            'rate_percent' => $data['rate_percent'],
            'inclusive' => $data['inclusive'] ?? false,
            'active' => true,
        ]);

        AuditLog::create([
            'organization_id' => $organization->id,
            'actor_user_id' => $request->user()->id,
            'action' => 'nexisp.accounting.tax_rate_created',
            'subject_type' => \App\Models\NexispTaxRate::class,
            'subject_id' => (string) $tax->id,
            'metadata' => ['code' => $tax->code, 'rate_percent' => $tax->rate_percent],
        ]);

        return response()->json(['data' => $tax], 201);
    }

    public function receipts(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $receipts = NexispReceipt::query()
            ->where('organization_id', $organization->id)
            ->with('invoice:id,invoice_number')
            ->latest('issued_at')
            ->paginate(30);

        return response()->json([
            'data' => $receipts->items(),
            'meta' => ['current_page' => $receipts->currentPage(), 'last_page' => $receipts->lastPage()],
        ]);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $rows = DB::table('nexisp_chart_of_accounts as accounts')
            ->leftJoin('nexisp_journal_entry_lines as lines', function ($join) {
                $join->on('lines.account_id', '=', 'accounts.id')
                    ->on('lines.organization_id', '=', 'accounts.organization_id');
            })
            ->leftJoin('nexisp_journal_entries as entries', function ($join) {
                $join->on('entries.id', '=', 'lines.journal_entry_id')
                    ->on('entries.organization_id', '=', 'lines.organization_id')
                    ->where('entries.status', '=', 'posted');
            })
            ->where('accounts.organization_id', $organization->id)
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type', 'accounts.normal_balance')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.account_type, accounts.normal_balance, COALESCE(SUM(CASE WHEN entries.id IS NOT NULL THEN lines.debit ELSE 0 END), 0) as debits, COALESCE(SUM(CASE WHEN entries.id IS NOT NULL THEN lines.credit ELSE 0 END), 0) as credits')
            ->get();

        $debits = $rows->sum(fn ($row) => (float) $row->debits);
        $credits = $rows->sum(fn ($row) => (float) $row->credits);

        return response()->json([
            'data' => $rows,
            'totals' => [
                'debits' => number_format($debits, 2, '.', ''),
                'credits' => number_format($credits, 2, '.', ''),
                'balanced' => abs($debits - $credits) < 0.005,
            ],
        ]);
    }

    public function profitAndLoss(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $query = DB::table('nexisp_journal_entry_lines as lines')
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
            ->whereIn('accounts.account_type', ['revenue', 'expense']);
        if (! empty($data['from'])) {
            $query->whereDate('entries.entry_date', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->whereDate('entries.entry_date', '<=', $data['to']);
        }

        $rows = $query->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.account_type')
            ->orderBy('accounts.code')
            ->selectRaw("accounts.code, accounts.name, accounts.account_type, CASE WHEN accounts.account_type = 'revenue' THEN COALESCE(SUM(lines.credit - lines.debit), 0) ELSE COALESCE(SUM(lines.debit - lines.credit), 0) END as amount")
            ->get();

        $revenue = (float) $rows->where('account_type', 'revenue')->sum(fn ($row) => (float) $row->amount);
        $expenses = (float) $rows->where('account_type', 'expense')->sum(fn ($row) => (float) $row->amount);

        return response()->json([
            'data' => $rows,
            'totals' => [
                'revenue' => number_format($revenue, 2, '.', ''),
                'expenses' => number_format($expenses, 2, '.', ''),
                'net_profit' => number_format($revenue - $expenses, 2, '.', ''),
            ],
            'period' => ['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null],
        ]);
    }

    public function agedReceivables(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);
        $invoices = NexispInvoice::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['open', 'partially_paid', 'overdue'])
            ->with(['customer:id,name,customer_number', 'items'])
            ->get();

        $buckets = ['current_0_30' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'over_90' => 0];
        $records = [];
        foreach ($invoices as $invoice) {
            $received = (float) NexispReceipt::query()
                ->where('organization_id', $organization->id)
                ->where('invoice_id', $invoice->id)
                ->sum('amount');
            $balance = max(0, (float) $invoice->amount - $received);
            if ($balance <= 0) {
                continue;
            }
            $baseDate = $invoice->due_at ?? $invoice->issued_at;
            $age = $baseDate === null || $baseDate->isFuture() ? 0 : (int) $baseDate->diffInDays(now());
            $bucket = match (true) {
                $age <= 30 => 'current_0_30',
                $age <= 60 => 'days_31_60',
                $age <= 90 => 'days_61_90',
                default => 'over_90',
            };
            $buckets[$bucket] += (int) round($balance * 100);
            $records[] = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'customer' => $invoice->customer?->name,
                'due_at' => $invoice->due_at,
                'days_overdue' => $age,
                'outstanding' => number_format($balance, 2, '.', ''),
                'currency' => $invoice->currency,
                'bucket' => $bucket,
            ];
        }

        return response()->json([
            'data' => $records,
            'totals' => array_map(fn (int $cents) => number_format($cents / 100, 2, '.', ''), $buckets),
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
