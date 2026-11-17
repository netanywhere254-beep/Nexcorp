<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantRouteEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_context_requires_authentication(): void
    {
        $this->getJson('/organization/context')->assertUnauthorized();
    }

    public function test_organization_context_requires_a_selected_active_membership(): void
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
            ->getJson('/organization/context')
            ->assertForbidden();
    }

    public function test_organization_context_returns_only_the_verified_tenant_and_role(): void
    {
        $user = $this->makeUser();
        $organization = $this->makeOrganization();
        $otherOrganization = $this->makeOrganization('Other Tenant');

        $user->organizations()->attach($organization->id, [
            'role' => 'billing',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->organizations()->attach($otherOrganization->id, [
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['active_organization_id' => $organization->id])
            ->getJson('/organization/context')
            ->assertOk()
            ->assertJsonPath('data.organization.id', $organization->id)
            ->assertJsonPath('data.organization.name', $organization->name)
            ->assertJsonPath('data.membership.role', 'billing')
            ->assertJsonPath('data.membership.status', 'active')
            ->assertJsonMissingPath('data.other_organization');
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Tenant Route Test',
            'email' => 'tenant-route-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ]);
    }

    private function makeOrganization(string $name = 'Tenant Route Test'): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => 'tenant-route-'.uniqid(),
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
    }
}
