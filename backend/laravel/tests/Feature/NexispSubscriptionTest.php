<?php

namespace Tests\Feature;

use App\Models\BandwidthPlan;
use App\Models\NexispSubscription;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexispSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_subscription_uses_server_plan_snapshot_and_price(): void
    {
        [$org, $manager] = $this->orgWithUser('manager');
        $customer = $this->makeUser();
        $plan = $this->makePlan($org, ['price' => '4500.00', 'currency' => 'KES', 'validity_days' => 30]);

        $response = $this->actingAs($manager)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/subscriptions', [
                'user_id' => $customer->id,
                'bandwidth_plan_id' => $plan->id,
                'price_amount' => '1.00', // must be ignored
                'currency' => 'USD',      // must be ignored
                'status' => 'active',     // must be ignored
            ])
            ->assertCreated()
            ->assertJsonPath('subscription.status', 'pending_payment')
            ->assertJsonPath('subscription.price_amount', '4500.00')
            ->assertJsonPath('subscription.currency', 'KES')
            ->assertJsonPath('subscription.plan_snapshot.code', $plan->code);

        $this->assertDatabaseHas('nexisp_subscriptions', [
            'organization_id' => $org->id,
            'user_id' => $customer->id,
            'bandwidth_plan_id' => $plan->id,
            'status' => 'pending_payment',
            'price_amount' => '4500.00',
            'currency' => 'KES',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'action' => 'nexisp.subscription_created',
        ]);
    }

    public function test_create_subscription_is_idempotent_per_organization(): void
    {
        [$org, $manager] = $this->orgWithUser('manager');
        $customer = $this->makeUser();
        $plan = $this->makePlan($org);

        $payload = [
            'user_id' => $customer->id,
            'bandwidth_plan_id' => $plan->id,
            'idempotency_key' => 'checkout-123',
        ];

        $first = $this->actingAs($manager)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/subscriptions', $payload)
            ->assertCreated();

        $second = $this->actingAs($manager)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/subscriptions', $payload)
            ->assertOk();

        $this->assertSame($first->json('subscription.id'), $second->json('subscription.id'));
        $this->assertDatabaseCount('nexisp_subscriptions', 1);
    }

    public function test_cannot_create_subscription_with_cross_tenant_or_inactive_plan(): void
    {
        [$orgA] = $this->orgWithUser('manager', 'org-a');
        [$orgB, $managerB] = $this->orgWithUser('manager', 'org-b');
        $customer = $this->makeUser();

        $foreignPlan = $this->makePlan($orgA, ['code' => 'FOREIGN']);
        $inactivePlan = $this->makePlan($orgB, ['code' => 'INACTIVE', 'is_active' => false]);

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->postJson('/nexisp/subscriptions', [
                'user_id' => $customer->id,
                'bandwidth_plan_id' => $foreignPlan->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bandwidth_plan_id']);

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->postJson('/nexisp/subscriptions', [
                'user_id' => $customer->id,
                'bandwidth_plan_id' => $inactivePlan->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('nexisp_subscriptions', 0);
    }

    public function test_subscription_reads_and_transitions_are_tenant_scoped_and_validated(): void
    {
        [$orgA, $managerA] = $this->orgWithUser('manager', 'org-a');
        [$orgB, $managerB] = $this->orgWithUser('manager', 'org-b');
        $customer = $this->makeUser();
        $plan = $this->makePlan($orgA);

        $sub = NexispSubscription::create([
            'organization_id' => $orgA->id,
            'user_id' => $customer->id,
            'bandwidth_plan_id' => $plan->id,
            'subscription_number' => 'SUB-TEST-1',
            'status' => 'pending_payment',
            'price_amount' => '4500.00',
            'currency' => 'KES',
            'plan_snapshot' => ['code' => $plan->code, 'validity_days' => 30],
        ]);

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->getJson('/nexisp/subscriptions/'.$sub->id)
            ->assertNotFound();

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->postJson('/nexisp/subscriptions/'.$sub->id.'/activate')
            ->assertNotFound();

        $this->actingAs($managerA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->postJson('/nexisp/subscriptions/'.$sub->id.'/suspend')
            ->assertStatus(409);

        $this->actingAs($managerA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->postJson('/nexisp/subscriptions/'.$sub->id.'/activate')
            ->assertOk()
            ->assertJsonPath('subscription.status', 'active');

        $sub->refresh();
        $this->assertNotNull($sub->activated_at);
        $this->assertNotNull($sub->starts_at);
        $this->assertNotNull($sub->ends_at);

        $this->actingAs($managerA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->postJson('/nexisp/subscriptions/'.$sub->id.'/suspend')
            ->assertOk()
            ->assertJsonPath('subscription.status', 'suspended');

        $this->actingAs($managerA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->postJson('/nexisp/subscriptions/'.$sub->id.'/cancel')
            ->assertOk()
            ->assertJsonPath('subscription.status', 'cancelled');
    }

    public function test_subscription_write_requires_network_management_role(): void
    {
        [$org, $billing] = $this->orgWithUser('billing');
        $customer = $this->makeUser();
        $plan = $this->makePlan($org);

        $this->actingAs($billing)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/subscriptions', [
                'user_id' => $customer->id,
                'bandwidth_plan_id' => $plan->id,
            ])
            ->assertForbidden();
    }

    private function makePlan(Organization $org, array $overrides = []): BandwidthPlan
    {
        return BandwidthPlan::create(array_merge([
            'organization_id' => $org->id,
            'code' => 'PLAN-'.uniqid(),
            'name' => 'Test Plan',
            'speed_mbps' => 10,
            'price' => '3000.00',
            'currency' => 'KES',
            'validity_days' => 30,
            'is_active' => true,
        ], $overrides));
    }

    private function makeUser(): User
    {
        $user = User::create([
            'name' => 'Customer',
            'email' => uniqid('cust').'@example.test',
            'password' => 'password',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user;
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function orgWithUser(string $role, string $slug = 'org'): array
    {
        $org = Organization::create([
            'name' => 'Org '.strtoupper($slug),
            'slug' => $slug.'-'.uniqid(),
            'type' => 'isp',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);

        $user = $this->makeUser();
        $org->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$org, $user];
    }
}
