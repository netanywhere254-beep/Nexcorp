<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\AccountingSyncItem;
use App\Models\AccountingSyncRun;
use App\Models\AuditLog;
use App\Models\NexispInvoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountingSyncService
{
    public function queueInvoice(
        User $actor,
        Organization $organization,
        int $connectionId,
        int $invoiceId,
    ): AccountingSyncItem {
        Gate::forUser($actor)->authorize('manageBilling', $organization);

        try {
            return DB::transaction(function () use ($actor, $organization, $connectionId, $invoiceId): AccountingSyncItem {
                $connection = AccountingConnection::query()
                    ->where('organization_id', $organization->id)
                    ->whereKey($connectionId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($connection->status !== 'connected') {
                    throw ValidationException::withMessages([
                        'connection' => 'Connect or reactivate the accounting account before exporting records.',
                    ]);
                }

                $invoice = NexispInvoice::query()
                    ->where('organization_id', $organization->id)
                    ->whereKey($invoiceId)
                    ->firstOrFail();

                $requiredMappings = ['accounts_receivable', 'subscription_revenue'];
                $mapped = $connection->mappings()
                    ->where('organization_id', $organization->id)
                    ->where('enabled', true)
                    ->whereIn('mapping_type', $requiredMappings)
                    ->pluck('mapping_type')
                    ->all();

                if (count(array_unique($mapped)) !== count($requiredMappings)) {
                    throw ValidationException::withMessages([
                        'mappings' => 'Map both accounts receivable and subscription revenue before exporting invoices.',
                    ]);
                }

                $idempotencyKey = 'nexisp.invoice.'.$invoice->id.'.v1';
                $existing = AccountingSyncItem::query()
                    ->where('organization_id', $organization->id)
                    ->where('accounting_connection_id', $connection->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $payload = [
                    'invoice_number' => $invoice->invoice_number,
                    'customer_id' => $invoice->customer_id,
                    'amount' => (string) $invoice->amount,
                    'currency' => $invoice->currency,
                    'issued_at' => $invoice->issued_at?->toIso8601String(),
                    'due_at' => $invoice->due_at?->toIso8601String(),
                ];

                $run = AccountingSyncRun::create([
                    'organization_id' => $organization->id,
                    'accounting_connection_id' => $connection->id,
                    'started_by_user_id' => $actor->id,
                    'direction' => 'export',
                    'status' => 'queued',
                    'items_total' => 1,
                    'summary' => ['resource' => 'invoice', 'invoice_id' => $invoice->id],
                ]);

                $item = AccountingSyncItem::create([
                    'organization_id' => $organization->id,
                    'accounting_connection_id' => $connection->id,
                    'accounting_sync_run_id' => $run->id,
                    'local_type' => NexispInvoice::class,
                    'local_id' => $invoice->id,
                    'operation' => 'upsert',
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                    'status' => 'queued',
                ]);

                AuditLog::create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $actor->id,
                    'action' => 'nexisp.accounting.invoice_export_queued',
                    'subject_type' => AccountingSyncItem::class,
                    'subject_id' => (string) $item->id,
                    'metadata' => [
                        'provider' => $connection->provider,
                        'connection_id' => $connection->id,
                        'invoice_id' => $invoice->id,
                        'sync_run_id' => $run->id,
                    ],
                ]);

                return $item;
            });
        } catch (UniqueConstraintViolationException) {
            $existing = AccountingSyncItem::query()
                ->where('organization_id', $organization->id)
                ->where('accounting_connection_id', $connectionId)
                ->where('idempotency_key', 'nexisp.invoice.'.$invoiceId.'.v1')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            throw ValidationException::withMessages([
                'sync' => 'The export could not be queued safely. Retry the request.',
            ]);
        }
    }
}
