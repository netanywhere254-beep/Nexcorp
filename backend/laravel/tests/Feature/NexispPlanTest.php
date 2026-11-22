<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BandwidthPlan;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexispPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_member_can_create_and_list_bandwidth_plans(): void
    {
        [$org, $manager] = $this->orgWithUser('manager');

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/plans', [
                'code' => 'home-10',
                'name' => 'Home 10 Mbps',
                'speed_mbps' => 10,
                'price' => '2500.00',
                'currency' => 'kes',
                'validity_days' => 30,
            ])
            ->assertCreated()
            ->assertJsonPath('plan.code', 'HOME-10')
            ->assertJsonPath('plan.currency', 'KES')
            ->assertJsonPath('plan.is_active', true);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $org->id])
            ->getJson('/nexisp/plans')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'HOME-10');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'action' => 'nexisp.plan_created',
        ]);
    }

    public function test_plan_write_requires_network_management_role(): void
    {
        [$org, $billing] = $this->orgWithUser('billing');

        $this->actingAs($billing)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/plans', [
                'code' => 'BIZ-50',
                'name' => 'Business 50',
                'speed_mbps' => 50,
                'price' => '9000',
                'currency' => 'KES',
                'validity_days' => 30,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('nexisp_bandwidth_plans', 0);
    }

    public function test_plan_code_is_unique_per_organization_but_can_repeat_across_tenants(): void
    {
        [$orgA, $managerA] = $this->orgWithUser('manager', 'org-a');
        [$orgB, $managerB] = $this->orgWithUser('manager', 'org-b');

        $payload = [
            'code' => 'FIBER-100',
            'name' => 'Fiber 100',
            'speed_mbps' => 100,
            'price' => '12000',
            'currency' => 'KES',
            'validity_days' => 30,
        ];

        $this->actingAs($managerA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->postJson('/nexisp/plans', $payload)
            ->assertCreated();

        $this->actingAs($managerA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->postJson('/nexisp/plans', array_merge($payload, ['code' => 'fiber-100']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->postJson('/nexisp/plans', $payload)
            ->assertCreated();
    }

    public function test_plan_reads_and_updates_are_tenant_scoped(): void
    {
        [$orgA, $managerA] = $this->orgWithUser('manager', 'org-a');
        [$orgB, $managerB] = $this->orgWithUser('manager', 'org-b');

        $plan = BandwidthPlan::create([
            'organization_id' => $orgA->id,
            'code' => 'A-PLAN',
            'name' => 'Org A Plan',
            'speed_mbps' => 20,
            'price' => '3000.00',
            'currency' => 'KES',
            'validity_days' => 30,
            'is_active' => true,
        ]);

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->getJson('/nexisp/plans/'.$plan->id)
            ->assertNotFound();

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->patchJson('/nexisp/plans/'.$plan->id, ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertDatabaseHas('nexisp_bandwidth_plans', ['id' => $plan->id, 'name' => 'Org A Plan']);

        $this->actingAs($managerA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->patchJson('/nexisp/plans/'.$plan->id, ['is_active' => false, 'price' => '3500.00'])
            ->assertOk()
            ->assertJsonPath('plan.is_active', false)
            ->assertJsonPath('plan.price', '3500.00');
    }

    public function test_plan_validation_rejects_invalid_values(): void
    {
        [$org, $manager] = $this->orgWithUser('manager');

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/plans', [
                'code' => 'BAD CODE',
                'name' => '',
                'speed_mbps' => 0,
                'price' => -1,
                'currency' => 'KENYA',
                'validity_days' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name', 'speed_mbps', 'price', 'currency', 'validity_days']);
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

        $user = User::create([
            'name' => 'Test User',
            'email' => uniqid('user').'@example.test',
            'password' => 'password',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $org->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$org, $user];
    }
}
