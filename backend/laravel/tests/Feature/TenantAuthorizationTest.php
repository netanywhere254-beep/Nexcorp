<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireActiveOrganization;
use App\Models\Organization;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', RequireActiveOrganization::class])
            ->get('/_test/tenant-context', fn (Request $request) => response()->json([
                'organization_id' => $request->attributes->get('activeOrganization')->id,
            ]));
    }

    public function test_tenant_route_requires_an_authenticated_user(): void
    {
        $this->getJson('/_test/tenant-context')->assertUnauthorized();
    }

    public function test_tenant_route_requires_a_selected_organization(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->getJson('/_test/tenant-context')->assertStatus(409);
    }

    public function test_tenant_route_rejects_an_unrelated_or_suspended_membership(): void
    {
        $user = $this->makeUser();
        $organization = $this->makeOrganization();
        $user->organizations()->attach($organization->id, [
            'role' => 'member',
            'status' => 'suspended',
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->getJson('/_test/tenant-context')
            ->assertForbidden();
    }

    public function test_tenant_route_uses_server_verified_active_organization(): void
    {
        $user = $this->makeUser();
        $organization = $this->makeOrganization();
        $user->organizations()->attach($organization->id, [
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->getJson('/_test/tenant-context')
            ->assertOk()
            ->assertJsonPath('organization_id', $organization->id);
    }

    public function test_only_owner_and_admin_can_manage_members(): void
    {
        $organization = $this->makeOrganization();
        $owner = $this->makeUser();
        $manager = $this->makeUser();
        $owner->organizations()->attach($organization->id, [
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);
        $manager->organizations()->attach($organization->id, [
            'role' => 'manager', 'status' => 'active', 'joined_at' => now(),
        ]);

        $policy = new OrganizationPolicy();

        $this->assertTrue($policy->manageMembers($owner, $organization));
        $this->assertFalse($policy->manageMembers($manager, $organization));
    }

    public function test_billing_role_can_manage_billing_but_not_members(): void
    {
        $organization = $this->makeOrganization();
        $billing = $this->makeUser();
        $billing->organizations()->attach($organization->id, [
            'role' => 'billing', 'status' => 'active', 'joined_at' => now(),
        ]);

        $policy = new OrganizationPolicy();

        $this->assertTrue($policy->manageBilling($billing, $organization));
        $this->assertFalse($policy->manageMembers($billing, $organization));
    }

    public function test_policy_rejects_inactive_users_and_organizations(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser(['status' => 'suspended']);
        $user->organizations()->attach($organization->id, [
            'role' => 'owner', 'status' => 'active', 'joined_at' => now(),
        ]);

        $policy = new OrganizationPolicy();

        $this->assertFalse($policy->view($user, $organization));
        $this->assertFalse($policy->update($user, $organization));
    }


    public function test_real_organization_context_endpoint_returns_only_server_verified_tenant_and_permissions(): void
    {
        $user = $this->makeUser();
        $organization = $this->makeOrganization();
        $user->organizations()->attach($organization->id, [
            'role' => 'billing',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->getJson('/auth/organization/context')
            ->assertOk()
            ->assertJsonPath('organization.id', $organization->id)
            ->assertJsonPath('membership.role', 'billing')
            ->assertJsonPath('permissions.view', true)
            ->assertJsonPath('permissions.manage_billing', true)
            ->assertJsonPath('permissions.manage_members', false);
    }

    public function test_real_organization_context_endpoint_rejects_a_client_selected_non_member_tenant(): void
    {
        $user = $this->makeUser();
        $organization = $this->makeOrganization();

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->getJson('/auth/organization/context')
            ->assertForbidden();
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Tenant Test User',
            'email' => 'tenant-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ], $overrides));
    }

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Tenant Test',
            'slug' => 'tenant-'.uniqid(),
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
    }
}
