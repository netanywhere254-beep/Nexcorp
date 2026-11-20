<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplicationInboxTest extends TestCase
{
    use RefreshDatabase;

    private function signedRequest(array $event, ?string $signatureOverride = null)
    {
        config(['services.nexora_replication.secret' => 'test-replication-secret']);
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = $signatureOverride ?? hash_hmac(
            'sha256',
            $timestamp.'.'.$body,
            'test-replication-secret'
        );

        return $this->call('POST', '/api/replication/events', [], [], [], [
            'HTTP_X_NEXORA_TIMESTAMP' => $timestamp,
            'HTTP_X_NEXORA_SIGNATURE' => $signature,
            'HTTP_IDEMPOTENCY_KEY' => $event['event_uuid'],
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function event(string $uuid = 'b6cfe8c4-0a6b-4a4d-8f9c-9c9b8b8b8b81'): array
    {
        return [
            'event_uuid' => $uuid,
            'idempotency_key' => 'replication-test-1',
            'organization_id' => 1,
            'user_id' => 42,
            'product_code' => 'nexisp',
            'aggregate_type' => 'subscription',
            'aggregate_id' => '44',
            'event_type' => 'subscription.activated',
            'aggregate_version' => 1,
            'payload' => ['subscription_id' => 44, 'status' => 'active'],
            'occurred_at' => now()->toISOString(),
        ];
    }

    public function test_valid_signed_event_is_durably_accepted(): void
    {
        $event = $this->event();

        $this->signedRequest($event)->assertAccepted()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('event_uuid', $event['event_uuid']);

        $this->assertDatabaseHas('client_change_inbox', [
            'event_uuid' => $event['event_uuid'],
            'product_code' => 'nexisp',
            'aggregate_type' => 'subscription',
            'aggregate_id' => '44',
        ]);
    }

    public function test_exact_replay_is_acknowledged_without_creating_a_second_inbox_row(): void
    {
        $event = $this->event();

        $this->signedRequest($event)->assertAccepted();
        $this->signedRequest($event)->assertAccepted()
            ->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('client_change_inbox', 1);
    }

    public function test_same_event_uuid_with_different_body_is_rejected(): void
    {
        $event = $this->event();
        $this->signedRequest($event)->assertAccepted();

        $changed = $event;
        $changed['payload']['status'] = 'cancelled';

        $this->signedRequest($changed)->assertStatus(409);
        $this->assertDatabaseCount('client_change_inbox', 1);
    }

    public function test_invalid_hmac_is_rejected(): void
    {
        $this->signedRequest($this->event(), str_repeat('a', 64))->assertUnauthorized();
        $this->assertDatabaseCount('client_change_inbox', 0);
    }

    public function test_reused_idempotency_key_with_a_new_event_uuid_is_rejected(): void
    {
        $first = $this->event();
        $this->signedRequest($first)->assertAccepted();

        $second = $this->event('d7d8e9f0-1234-4abc-8def-0123456789ab');
        $second['aggregate_version'] = 2;
        $second['payload']['status'] = 'cancelled';

        $this->signedRequest($second)->assertStatus(409);
        $this->assertDatabaseCount('client_change_inbox', 1);
    }

}
