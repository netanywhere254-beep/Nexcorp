<?php

namespace App\Services;

use App\Models\ClientServiceIdentity;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ClientChangeInboxProcessor
{
    /**
     * Apply only the explicitly supported identity event contract.
     * Unknown events remain pending until a product-specific handler exists.
     */
    public function processPending(int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));
        $ids = DB::table('client_change_inbox')
            ->whereNull('processed_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $result = ['processed' => 0, 'failed' => 0, 'blocked' => 0];

        foreach ($ids as $id) {
            try {
                $outcome = $this->processOne((int) $id);
                if ($outcome === 'processed') {
                    $result['processed']++;
                } elseif ($outcome === 'blocked') {
                    $result['blocked']++;
                }
            } catch (Throwable $exception) {
                DB::table('client_change_inbox')->where('id', $id)->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => mb_substr($exception->getMessage(), 0, 60000),
                    'updated_at' => now(),
                ]);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function processOne(int $id): string
    {
        return DB::transaction(function () use ($id): string {
            $event = DB::table('client_change_inbox')->where('id', $id)->lockForUpdate()->first();
            if (! $event || $event->processed_at !== null) {
                return 'blocked';
            }

            $earlierPending = DB::table('client_change_inbox')
                ->where('organization_id', $event->organization_id)
                ->where('product_code', $event->product_code)
                ->where('aggregate_type', $event->aggregate_type)
                ->where('aggregate_id', $event->aggregate_id)
                ->where('aggregate_version', '<', $event->aggregate_version)
                ->whereNull('processed_at')
                ->exists();

            if ($earlierPending) {
                return 'blocked';
            }

            if ($event->event_type !== 'client.identity.upserted'
                || $event->aggregate_type !== 'client_identity') {
                throw new RuntimeException('No inbox handler is registered for this event type.');
            }

            if ($event->organization_id === null || $event->user_id === null) {
                throw new RuntimeException('Identity events require an organisation and user.');
            }

            $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);
            $externalReference = (string) ($payload['external_reference'] ?? '');
            $status = (string) ($payload['status'] ?? 'active');
            if ($externalReference === '' || $externalReference !== (string) $event->aggregate_id
                || ! in_array($status, ['active', 'suspended', 'closed'], true)) {
                throw new RuntimeException('Identity event payload does not match the supported contract.');
            }

            if (! Organization::query()->whereKey($event->organization_id)->exists()
                || ! User::query()->whereKey($event->user_id)->exists()) {
                throw new RuntimeException('Referenced organisation or user is not present on this backup.');
            }

            $identity = ClientServiceIdentity::query()
                ->where('organization_id', $event->organization_id)
                ->where('product_code', $event->product_code)
                ->where('external_reference', $externalReference)
                ->lockForUpdate()
                ->first();

            $expectedVersion = $identity ? ((int) $identity->source_version + 1) : 1;
            if ((int) $event->aggregate_version !== $expectedVersion) {
                throw new RuntimeException("Identity version gap: expected {$expectedVersion}, received {$event->aggregate_version}.");
            }

            if ($identity && (int) $identity->user_id !== (int) $event->user_id) {
                throw new RuntimeException('Identity external reference is already linked to another user.');
            }

            if (! $identity) {
                $identity = new ClientServiceIdentity();
                $identity->organization_id = $event->organization_id;
                $identity->product_code = $event->product_code;
                $identity->external_reference = $externalReference;
            }

            $identity->user_id = $event->user_id;
            $identity->status = $status;
            $identity->metadata = $payload['metadata'] ?? null;
            $identity->source_version = $event->aggregate_version;
            $identity->save();

            DB::table('client_change_inbox')->where('id', $id)->update([
                'processed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'last_error' => null,
                'updated_at' => now(),
            ]);

            return 'processed';
        }, 5);
    }
}
