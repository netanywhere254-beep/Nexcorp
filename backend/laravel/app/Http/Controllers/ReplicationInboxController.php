<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReplicationInboxController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $secret = (string) config('services.nexora_replication.secret');
        $timestamp = (string) $request->header('X-Nexora-Timestamp', '');
        $signature = (string) $request->header('X-Nexora-Signature', '');
        $rawBody = $request->getContent();

        if ($secret === '' || ! ctype_digit($timestamp) || ! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return response()->json(['message' => 'Replication authentication failed.'], 401);
        }

        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return response()->json(['message' => 'Replication request timestamp is outside the allowed window.'], 401);
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        if (! hash_equals($expected, strtolower($signature))) {
            return response()->json(['message' => 'Replication authentication failed.'], 401);
        }

        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->json(['message' => 'Invalid replication event JSON.'], 422);
        }

        $validator = validator($event, [
            'event_uuid' => ['required', 'uuid'],
            'idempotency_key' => ['nullable', 'string', 'max:150'],
            'organization_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'product_code' => ['required', 'in:nexora,nexisp,loopza'],
            'aggregate_type' => ['required', 'string', 'max:100'],
            'aggregate_id' => ['required', 'string', 'max:120'],
            'event_type' => ['required', 'string', 'max:120'],
            'aggregate_version' => ['required', 'integer', 'min:1'],
            'payload' => ['required', 'array'],
            'occurred_at' => ['required', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid replication event.', 'errors' => $validator->errors()], 422);
        }

        $idempotencyHeader = (string) $request->header('Idempotency-Key', '');
        if (! hash_equals((string) $event['event_uuid'], $idempotencyHeader)) {
            return response()->json(['message' => 'Idempotency key does not match event UUID.'], 422);
        }

        $eventHash = hash('sha256', $rawBody);

        $result = DB::transaction(function () use ($event, $eventHash): array {
            $existing = DB::table('client_change_inbox')
                ->where('event_uuid', $event['event_uuid'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->event_hash, $eventHash)) {
                    return ['accepted' => false, 'conflict' => true];
                }

                return ['accepted' => true, 'duplicate' => true];
            }

            if (filled($event['idempotency_key'] ?? null)) {
                $keyOwner = DB::table('client_change_inbox')
                    ->where('idempotency_key', $event['idempotency_key'])
                    ->lockForUpdate()
                    ->first();

                if ($keyOwner) {
                    return ['accepted' => false, 'conflict' => true];
                }
            }

            DB::table('client_change_inbox')->insert([
                'event_uuid' => $event['event_uuid'],
                'idempotency_key' => $event['idempotency_key'] ?? null,
                'event_hash' => $eventHash,
                'organization_id' => $event['organization_id'] ?? null,
                'user_id' => $event['user_id'] ?? null,
                'product_code' => $event['product_code'],
                'aggregate_type' => $event['aggregate_type'],
                'aggregate_id' => (string) $event['aggregate_id'],
                'event_type' => $event['event_type'],
                'aggregate_version' => $event['aggregate_version'],
                'payload' => json_encode($event['payload'], JSON_THROW_ON_ERROR),
                'occurred_at' => $event['occurred_at'],
                'received_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['accepted' => true, 'duplicate' => false];
        }, 5);

        if ($result['conflict'] ?? false) {
            return response()->json(['message' => 'Event UUID was already used for a different event.'], 409);
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => $result['duplicate'],
            'event_uuid' => $event['event_uuid'],
        ], 202);
    }
}
