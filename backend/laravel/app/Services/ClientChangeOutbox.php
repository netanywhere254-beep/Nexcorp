<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ClientChangeOutbox
{
    private const PRODUCTS = ['nexisp', 'loopza', 'nexora'];

    public function record(
        string $productCode,
        string $aggregateType,
        string|int $aggregateId,
        string $eventType,
        array $payload,
        ?int $organizationId = null,
        ?int $userId = null,
        ?string $idempotencyKey = null,
        int $aggregateVersion = 1,
    ): int {
        if (! in_array($productCode, self::PRODUCTS, true)) {
            throw new InvalidArgumentException('Unsupported product code.');
        }
        if ($aggregateType === '' || $eventType === '' || $aggregateVersion < 1) {
            throw new InvalidArgumentException('A valid aggregate, event type and version are required.');
        }
        if ($idempotencyKey !== null && strlen($idempotencyKey) > 150) {
            throw new InvalidArgumentException('Idempotency key is too long.');
        }

        return DB::transaction(function () use (
            $productCode, $aggregateType, $aggregateId, $eventType, $payload,
            $organizationId, $userId, $idempotencyKey, $aggregateVersion
        ): int {
            if ($idempotencyKey !== null) {
                $existing = DB::table('client_change_outbox')
                    ->where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    $sameEvent = $existing->product_code === $productCode
                        && $existing->aggregate_type === $aggregateType
                        && (string) $existing->aggregate_id === (string) $aggregateId
                        && $existing->event_type === $eventType
                        && (int) $existing->aggregate_version === $aggregateVersion
                        && json_decode($existing->payload, true) === $payload;
                    if (! $sameEvent) {
                        throw new RuntimeException('Idempotency key was already used for a different client event.');
                    }

                    return (int) $existing->id;
                }
            }

            return (int) DB::table('client_change_outbox')->insertGetId([
                'event_uuid' => (string) Str::uuid(),
                'idempotency_key' => $idempotencyKey,
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'product_code' => $productCode,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => (string) $aggregateId,
                'event_type' => $eventType,
                'aggregate_version' => $aggregateVersion,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 5);
    }
}
