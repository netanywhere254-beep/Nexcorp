<?php

namespace Tests\Feature;

use App\Models\ClientServiceIdentity;
use App\Models\Organization;
use App\Models\User;
use App\Services\ClientChangeOutbox;
use App\Services\ClientChangeOutboxPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ClientContinuityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_group_user_can_have_separate_product_references(): void
    {
        $organization = Organization::create([
            'name' => 'Nexora Test', 'slug' => 'nexora-test', 'type' => 'group',
            'status' => 'active', 'country_code' => 'KE', 'timezone' => 'Africa/Nairobi',
        ]);
        $user = User::create([
            'name' => 'Test Client', 'email' => 'client@example.test',
            'password' => 'password', 'status' => 'active',
        ]);

        ClientServiceIdentity::create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'product_code' => 'nexisp', 'external_reference' => 'ISP-00001',
        ]);
        ClientServiceIdentity::create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'product_code' => 'loopza', 'external_reference' => 'LOOP-00001',
        ]);

        $this->assertDatabaseCount('client_service_identities', 2);
        $this->assertSame($user->id, ClientServiceIdentity::where('product_code', 'nexisp')->value('user_id'));
        $this->assertSame($user->id, ClientServiceIdentity::where('product_code', 'loopza')->value('user_id'));
    }

    public function test_outbox_records_a_replay_safe_client_change_once(): void
    {
        $service = app(ClientChangeOutbox::class);
        $payload = ['subscription_id' => 44, 'status' => 'active'];

        $first = $service->record('nexisp', 'subscription', 44, 'subscription.activated', $payload, null, null, 'evt-44-v1');
        $second = $service->record('nexisp', 'subscription', 44, 'subscription.activated', $payload, null, null, 'evt-44-v1');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('client_change_outbox', 1);
    }

    public function test_outbox_rejects_reuse_of_idempotency_key_for_different_event(): void
    {
        $service = app(ClientChangeOutbox::class);
        $service->record('loopza', 'order', 12, 'order.paid', ['order_id' => 12], null, null, 'payment-webhook-12');

        $this->expectException(RuntimeException::class);
        $service->record('loopza', 'order', 12, 'order.cancelled', ['order_id' => 12], null, null, 'payment-webhook-12');
    }

    public function test_outbox_does_not_replay_an_idempotency_key_across_organisations(): void
    {
        $firstOrganization = Organization::create([
            'name' => 'First Tenant', 'slug' => 'first-tenant', 'type' => 'business',
            'status' => 'active', 'country_code' => 'KE', 'timezone' => 'Africa/Nairobi',
        ]);
        $secondOrganization = Organization::create([
            'name' => 'Second Tenant', 'slug' => 'second-tenant', 'type' => 'business',
            'status' => 'active', 'country_code' => 'KE', 'timezone' => 'Africa/Nairobi',
        ]);

        $service = app(ClientChangeOutbox::class);
        $service->record(
            'nexisp', 'subscription', 101, 'subscription.activated',
            ['subscription_id' => 101], $firstOrganization->id, null, 'tenant-scoped-event-key', 1
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Idempotency key was already used for a different client event');

        $service->record(
            'nexisp', 'subscription', 101, 'subscription.activated',
            ['subscription_id' => 101], $secondOrganization->id, null, 'tenant-scoped-event-key', 1
        );
    }

    public function test_outbox_requires_strictly_increasing_versions_for_an_aggregate(): void
    {
        $service = app(ClientChangeOutbox::class);
        $service->record(
            'nexisp', 'subscription', 91, 'subscription.activated',
            ['subscription_id' => 91], null, null, 'subscription-91-v1', 1
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Aggregate versions must increase monotonically');

        $service->record(
            'nexisp', 'subscription', 91, 'subscription.updated',
            ['subscription_id' => 91], null, null, 'subscription-91-v1-duplicate', 1
        );
    }

    public function test_outbox_event_rolls_back_with_the_business_transaction(): void
    {
        try {
            \Illuminate\Support\Facades\DB::transaction(function (): void {
                app(ClientChangeOutbox::class)->record(
                    'nexisp', 'subscription', 99, 'subscription.activated',
                    ['subscription_id' => 99], null, null, 'rollback-event-99'
                );

                throw new RuntimeException('Simulated business transaction failure.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated business transaction failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('client_change_outbox', 0);
    }

    public function test_publisher_marks_event_published_only_after_backup_acknowledges_it(): void
    {
        app(ClientChangeOutbox::class)->record(
            'nexisp', 'subscription', 44, 'subscription.activated',
            ['subscription_id' => 44], null, null, 'publisher-event-44'
        );
        config([
            'services.nexora_replication.url' => 'https://backup.example.test',
            'services.nexora_replication.secret' => 'test-shared-secret',
        ]);
        Http::fake(['backup.example.test/api/replication/events' => Http::response(['accepted' => true], 202)]);

        $result = app(ClientChangeOutboxPublisher::class)->publishPending();

        $this->assertSame(1, $result['published']);
        $this->assertSame(0, $result['failed']);
        $this->assertDatabaseCount('client_change_outbox', 1);
        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('client_change_outbox')->value('published_at'));
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://backup.example.test/api/replication/events'
            && $request->hasHeader('X-Nexora-Signature')
            && $request->hasHeader('Idempotency-Key')
        );
    }

    public function test_publisher_keeps_event_pending_and_counts_failure_when_backup_is_unavailable(): void
    {
        app(ClientChangeOutbox::class)->record(
            'loopza', 'order', 55, 'order.paid', ['order_id' => 55], null, null, 'publisher-event-55'
        );
        config([
            'services.nexora_replication.url' => 'https://backup.example.test',
            'services.nexora_replication.secret' => 'test-shared-secret',
        ]);
        Http::fake(['backup.example.test/api/replication/events' => Http::response(['error' => 'unavailable'], 503)]);

        $result = app(ClientChangeOutboxPublisher::class)->publishPending();

        $this->assertSame(0, $result['published']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseHas('client_change_outbox', [
            'idempotency_key' => 'publisher-event-55',
            'published_at' => null,
            'attempts' => 1,
            'last_error' => 'Replication endpoint returned HTTP 503',
        ]);
    }

    public function test_publisher_does_not_skip_an_earlier_unacknowledged_event_for_the_same_aggregate(): void
    {
        $service = app(ClientChangeOutbox::class);
        $service->record('nexisp', 'subscription', 77, 'subscription.updated', ['version' => 1], null, null, 'ordered-event-77-v1', 1);
        $service->record('nexisp', 'subscription', 77, 'subscription.updated', ['version' => 2], null, null, 'ordered-event-77-v2', 2);
        config([
            'services.nexora_replication.url' => 'https://backup.example.test',
            'services.nexora_replication.secret' => 'test-shared-secret',
        ]);
        Http::fake(['backup.example.test/api/replication/events' => Http::response(['error' => 'unavailable'], 503)]);

        $publisher = app(ClientChangeOutboxPublisher::class);
        $firstResult = $publisher->publishPending();
        $secondResult = $publisher->publishPending();

        $this->assertSame(1, $firstResult['failed']);
        $this->assertSame(0, $secondResult['published']);
        $this->assertSame(0, $secondResult['failed']);
        $this->assertSame(1, Http::recorded()->count());
        $this->assertDatabaseHas('client_change_outbox', [
            'idempotency_key' => 'ordered-event-77-v1',
            'published_at' => null,
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('client_change_outbox', [
            'idempotency_key' => 'ordered-event-77-v2',
            'published_at' => null,
            'attempts' => 0,
        ]);
    }

    public function test_outbox_rejects_unknown_product_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ClientChangeOutbox::class)->record('unknown', 'client', 1, 'client.updated', []);
    }
}
