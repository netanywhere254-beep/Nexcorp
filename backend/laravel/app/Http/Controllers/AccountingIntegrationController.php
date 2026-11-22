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
        ['key' => 'csv', 'name' => 'CSV export', 'connection' => 'download', 'available' => true],
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

    private function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('activeOrganization');
        abort_unless($organization instanceof Organization, 409, 'Select an active organization first.');

        return $organization;
    }
}
