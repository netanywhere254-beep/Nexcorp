<?php

namespace App\Http\Controllers;

use App\Models\AccountingConnection;
use App\Models\Organization;
use App\Services\AccountingSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccountingIntegrationController
{
    private const PROVIDERS = [
        ['key' => 'zoho_books', 'name' => 'Zoho Books', 'connection' => 'oauth2', 'available' => false],
        ['key' => 'quickbooks_online', 'name' => 'QuickBooks Online', 'connection' => 'oauth2', 'available' => false],
        ['key' => 'xero', 'name' => 'Xero', 'connection' => 'oauth2', 'available' => false],
        ['key' => 'sage', 'name' => 'Sage Accounting', 'connection' => 'oauth2', 'available' => false],
        ['key' => 'csv', 'name' => 'CSV export', 'connection' => 'download', 'available' => false],
    ];

    public function providers(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);

        return response()->json(['data' => self::PROVIDERS]);
    }

    public function connections(Request $request): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);

        $connections = AccountingConnection::query()
            ->where('organization_id', $organization->id)
            ->orderBy('provider')
            ->get([
                'id', 'provider', 'display_name', 'external_account_id',
                'status', 'granted_scopes', 'last_synced_at', 'created_at',
            ]);

        return response()->json(['data' => $connections]);
    }

    public function queueInvoiceExport(
        Request $request,
        int $connectionId,
        int $invoiceId,
        AccountingSyncService $sync,
    ): JsonResponse {
        $organization = $this->organization($request);
        $item = $sync->queueInvoice($request->user(), $organization, $connectionId, $invoiceId);

        return response()->json([
            'data' => [
                'id' => $item->id,
                'status' => $item->status,
                'idempotency_key' => $item->idempotency_key,
                'created_at' => $item->created_at,
            ],
        ], 202);
    }


    public function saveMappings(Request $request, int $connectionId): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);

        $data = $request->validate([
            'mappings' => ['required', 'array', 'min:1', 'max:30'],
            'mappings.*.mapping_type' => ['required', 'string', 'in:accounts_receivable,subscription_revenue,tax_payable,bank,processing_fees,operating_expense'],
            'mappings.*.local_code' => ['required', 'string', 'max:80'],
            'mappings.*.external_account_id' => ['required', 'string', 'max:160'],
            'mappings.*.external_account_name' => ['nullable', 'string', 'max:160'],
            'mappings.*.enabled' => ['sometimes', 'boolean'],
        ]);

        $connection = AccountingConnection::query()
            ->where('organization_id', $organization->id)
            ->whereKey($connectionId)
            ->firstOrFail();

        abort_unless($connection->status === 'connected', 409, 'The accounting connection is not active.');

        $saved = DB::transaction(function () use ($data, $connection, $organization, $request): array {
            $results = [];
            foreach ($data['mappings'] as $mapping) {
                $results[] = AccountingAccountMapping::updateOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'accounting_connection_id' => $connection->id,
                        'mapping_type' => $mapping['mapping_type'],
                        'local_code' => $mapping['local_code'],
                    ],
                    [
                        'external_account_id' => $mapping['external_account_id'],
                        'external_account_name' => $mapping['external_account_name'] ?? null,
                        'enabled' => $mapping['enabled'] ?? true,
                    ],
                );
            }

            AuditLog::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $request->user()->id,
                'action' => 'nexisp.accounting.mappings_updated',
                'subject_type' => AccountingConnection::class,
                'subject_id' => (string) $connection->id,
                'metadata' => ['mapping_count' => count($results), 'provider' => $connection->provider],
            ]);

            return $results;
        });

        return response()->json([
            'data' => collect($saved)->map(fn (AccountingAccountMapping $mapping) => [
                'id' => $mapping->id,
                'mapping_type' => $mapping->mapping_type,
                'local_code' => $mapping->local_code,
                'external_account_id' => $mapping->external_account_id,
                'external_account_name' => $mapping->external_account_name,
                'enabled' => $mapping->enabled,
            ])->values(),
        ]);
    }

    public function syncRuns(Request $request, int $connectionId): JsonResponse
    {
        $organization = $this->organization($request);
        Gate::authorize('manageBilling', $organization);

        $connection = AccountingConnection::query()
            ->where('organization_id', $organization->id)
            ->whereKey($connectionId)
            ->firstOrFail();

        $runs = AccountingSyncRun::query()
            ->where('organization_id', $organization->id)
            ->where('accounting_connection_id', $connection->id)
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => $runs->getCollection()->map(fn (AccountingSyncRun $run) => [
                'id' => $run->id,
                'direction' => $run->direction,
                'status' => $run->status,
                'items_total' => $run->items_total,
                'items_succeeded' => $run->items_succeeded,
                'items_failed' => $run->items_failed,
                'started_at' => $run->started_at,
                'finished_at' => $run->finished_at,
                'created_at' => $run->created_at,
            ])->values(),
            'meta' => ['current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage()],
        ]);
    }
\n    private function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('activeOrganization');
        abort_unless($organization instanceof Organization, 409, 'Select an active organization first.');

        return $organization;
    }
}
