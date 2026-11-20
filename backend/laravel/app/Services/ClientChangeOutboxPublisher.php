<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ClientChangeOutboxPublisher
{
    public function publishPending(int $limit = 100): array
    {
        $endpoint = rtrim((string) config('services.nexora_replication.url'), '/');
        $secret = (string) config('services.nexora_replication.secret');

        if ($endpoint === '' || $secret === '') {
            return ['published' => 0, 'failed' => 0, 'skipped' => $limit, 'configured' => false];
        }

        $published = 0;
        $failed = 0;
        $rows = DB::table('client_change_outbox')
            ->whereNull('published_at')
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->get();

        foreach ($rows as $row) {
            $body = [
                'event_uuid' => $row->event_uuid,
                'idempotency_key' => $row->idempotency_key,
                'organization_id' => $row->organization_id,
                'user_id' => $row->user_id,
                'product_code' => $row->product_code,
                'aggregate_type' => $row->aggregate_type,
                'aggregate_id' => $row->aggregate_id,
                'event_type' => $row->event_type,
                'aggregate_version' => (int) $row->aggregate_version,
                'payload' => json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR),
                'occurred_at' => $row->occurred_at,
            ];
            $timestamp = (string) now()->timestamp;
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
            $signature = hash_hmac('sha256', $timestamp.'.'.$encoded, $secret);

            try {
                $response = Http::acceptJson()
                    ->asJson()
                    ->timeout(10)
                    ->withHeaders([
                        'X-Nexora-Timestamp' => $timestamp,
                        'X-Nexora-Signature' => $signature,
                        'Idempotency-Key' => $row->event_uuid,
                    ])
                    ->post($endpoint.'/api/replication/events', $body);

                if ($response->successful()) {
                    DB::table('client_change_outbox')
                        ->where('id', $row->id)
                        ->whereNull('published_at')
                        ->update([
                            'published_at' => now(),
                            'last_error' => null,
                            'updated_at' => now(),
                        ]);
                    $published++;
                    continue;
                }

                $error = 'Replication endpoint returned HTTP '.$response->status();
            } catch (Throwable $exception) {
                $error = 'Replication request failed: '.class_basename($exception);
            }

            DB::table('client_change_outbox')
                ->where('id', $row->id)
                ->whereNull('published_at')
                ->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error' => $error,
                    'updated_at' => now(),
                ]);
            $failed++;
        }

        return ['published' => $published, 'failed' => $failed, 'skipped' => 0, 'configured' => true];
    }
}
