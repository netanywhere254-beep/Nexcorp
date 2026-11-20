<?php

namespace Tests\Feature;

use App\Models\ClientServiceIdentity;
use App\Models\Organization;
use App\Models\User;
use App\Services\ClientChangeOutbox;
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

    public function test_outbox_event_rolls_back_with_the_business_transaction(): void
    {
        try {
            \\Illuminate\\Support\\Facades\\DB::transaction(function (): void {
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

    public function test_outbox_rejects_unknown_product_code(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ClientChangeOutbox::class)->record('unknown', 'client', 1, 'client.updated', []);
    }
}
