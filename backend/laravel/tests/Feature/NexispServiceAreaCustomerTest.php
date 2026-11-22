<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NexispCustomer;
use App\Models\NexispServiceArea;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexispServiceAreaCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_member_can_create_and_list_service_areas_in_active_organization(): void
    {
        [$org, $manager] = $this->orgWithUser('manager');

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/service-areas', [
                'code' => 'nrb-west',
                'name' => 'Nairobi West',
                'region' => 'Nairobi',
                'description' => 'Westlands and surrounding estates',
            ])
            ->assertCreated()
            ->assertJsonPath('service_area.code', 'NRB-WEST')
            ->assertJsonPath('service_area.is_active', true);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $org->id])
            ->getJson('/nexisp/service-areas')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Nairobi West');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'actor_user_id' => $manager->id,
            'action' => 'nexisp.service_area_created',
        ]);
    }

    public function test_service_area_write_requires_network_management_role(): void
    {
        [$org, $member] = $this->orgWithUser('member');

        $this->actingAs($member)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/service-areas', [
                'code' => 'MSA-1',
                'name' => 'Mombasa',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('nexisp_service_areas', 0);
    }

    public function test_service_area_code_is_unique_per_organization(): void
    {
        [$orgA, $managerA] = $this->orgWithUser('manager', 'org-a');
        [$orgB, $managerB] = $this->orgWithUser('manager', 'org-b');

        $this->actingAs($managerA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->postJson('/nexisp/service-areas', ['code' => 'ZONE1', 'name' => 'Zone 1'])
            ->assertCreated();

        $this->actingAs($managerA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->postJson('/nexisp/service-areas', ['code' => 'zone1', 'name' => 'Duplicate'])
            ->assertUnprocessable();

        // Same code in a different tenant is allowed.
        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->postJson('/nexisp/service-areas', ['code' => 'ZONE1', 'name' => 'Zone 1'])
            ->assertCreated();
    }

    public function test_service_area_reads_and_updates_are_tenant_scoped(): void
    {
        [$orgA, $managerA] = $this->orgWithUser('manager', 'org-a');
        [$orgB, $managerB] = $this->orgWithUser('manager', 'org-b');

        $areaA = NexispServiceArea::create([
            'organization_id' => $orgA->id,
            'code' => 'A1',
            'name' => 'Area A',
            'is_active' => true,
        ]);

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->getJson('/nexisp/service-areas/'.$areaA->id)
            ->assertNotFound();

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->patchJson('/nexisp/service-areas/'.$areaA->id, ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertDatabaseHas('nexisp_service_areas', ['id' => $areaA->id, 'name' => 'Area A']);
    }

    public function test_authorized_member_can_create_customer_and_assign_same_tenant_service_area(): void
    {
        [$org, $manager] = $this->orgWithUser('manager');
        $area = NexispServiceArea::create([
            'organization_id' => $org->id,
            'code' => 'NRB-CBD',
            'name' => 'CBD',
            'is_active' => true,
        ]);

        $response = $this->actingAs($manager)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/customers', [
                'customer_number' => 'cust-001',
                'type' => 'business',
                'full_name' => 'Acme Ltd',
                'email' => 'OPS@ACME.TEST',
                'phone' => '+254700000001',
                'physical_address' => 'Kimathi Street',
                'service_area_id' => $area->id,
            ])
            ->assertCreated()
            ->assertJsonPath('customer.customer_number', 'CUST-001')
            ->assertJsonPath('customer.email', 'ops@acme.test')
            ->assertJsonPath('customer.service_area.id', $area->id);

        $this->assertDatabaseHas('nexisp_customers', [
            'organization_id' => $org->id,
            'customer_number' => 'CUST-001',
            'service_area_id' => $area->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'action' => 'nexisp.customer_created',
        ]);
    }

    public function test_customer_cannot_be_assigned_service_area_from_another_organization(): void
    {
        [$orgA] = $this->orgWithUser('manager', 'org-a');
        [$orgB, $managerB] = $this->orgWithUser('manager', 'org-b');

        $foreignArea = NexispServiceArea::create([
            'organization_id' => $orgA->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign',
            'is_active' => true,
        ]);

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->postJson('/nexisp/customers', [
                'customer_number' => 'CUST-100',
                'type' => 'residential',
                'full_name' => 'Jane Doe',
                'phone' => '+254700000002',
                'service_area_id' => $foreignArea->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_area_id']);

        $this->assertDatabaseCount('nexisp_customers', 0);
    }

    public function test_customer_reads_and_updates_are_tenant_scoped(): void
    {
        [$orgA, $managerA] = $this->orgWithUser('manager', 'org-a');
        [$orgB, $managerB] = $this->orgWithUser('manager', 'org-b');

        $customerA = NexispCustomer::create([
            'organization_id' => $orgA->id,
            'customer_number' => 'A-001',
            'type' => 'residential',
            'status' => 'active',
            'full_name' => 'Alice',
            'phone' => '+254700000003',
        ]);

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->getJson('/nexisp/customers/'.$customerA->id)
            ->assertNotFound();

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->patchJson('/nexisp/customers/'.$customerA->id, ['full_name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertDatabaseHas('nexisp_customers', ['id' => $customerA->id, 'full_name' => 'Alice']);

        $this->actingAs($managerA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->patchJson('/nexisp/customers/'.$customerA->id, ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('customer.status', 'suspended');
    }

    public function test_customer_number_is_unique_per_organization(): void
    {
        [$org, $manager] = $this->orgWithUser('manager');
        NexispCustomer::create([
            'organization_id' => $org->id,
            'customer_number' => 'CUST-1',
            'type' => 'residential',
            'status' => 'active',
            'full_name' => 'First',
            'phone' => '+254700000004',
        ]);

        $this->actingAs($manager)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/customers', [
                'customer_number' => 'cust-1',
                'type' => 'residential',
                'full_name' => 'Second',
                'phone' => '+254700000005',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_number']);
    }

    public function test_member_without_network_role_cannot_create_customer(): void
    {
        [$org, $member] = $this->orgWithUser('billing');

        $this->actingAs($member)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson('/nexisp/customers', [
                'customer_number' => 'CUST-X',
                'type' => 'residential',
                'full_name' => 'Blocked',
                'phone' => '+254700000006',
            ])
            ->assertForbidden();
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
