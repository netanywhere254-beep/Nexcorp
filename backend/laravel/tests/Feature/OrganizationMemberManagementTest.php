<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationMemberManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_member_can_list_members_in_their_organization(): void
    {
        [$org, $owner] = $this->orgWithUser('owner');
        $member = $this->makeUser('member@example.test');
        $this->attach($org, $member, 'member');

        $this->actingAs($member)
            ->withSession(['active_organization_id' => $org->id])
            ->getJson('/organization/members')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_update_member_role_but_cannot_assign_admin(): void
    {
        [$org, $owner] = $this->orgWithUser('owner');
        $admin = $this->makeUser('admin@example.test');
        $member = $this->makeUser('target@example.test');
        $this->attach($org, $admin, 'admin');
        $target = $this->attach($org, $member, 'member');

        $this->actingAs($admin)
            ->withSession(['active_organization_id' => $org->id])
            ->patchJson("/organization/members/{$target->id}", ['role' => 'manager'])
            ->assertOk()
            ->assertJsonPath('member.role', 'manager');

        $this->actingAs($admin)
            ->withSession(['active_organization_id' => $org->id])
            ->patchJson("/organization/members/{$target->id}", ['role' => 'admin'])
            ->assertForbidden();

        $this->assertDatabaseHas('organization_members', ['id' => $target->id, 'role' => 'manager']);
    }

    public function test_owner_can_assign_admin_and_suspend_member(): void
    {
        [$org, $owner] = $this->orgWithUser('owner');
        $member = $this->makeUser('target@example.test');
        $target = $this->attach($org, $member, 'member');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $org->id])
            ->patchJson("/organization/members/{$target->id}", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('member.role', 'admin');

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $org->id])
            ->patchJson("/organization/members/{$target->id}", ['status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('member.status', 'suspended');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'action' => 'organization.member_updated',
        ]);
    }

    public function test_owner_role_cannot_be_assigned_or_modified_through_endpoint(): void
    {
        [$org, $owner] = $this->orgWithUser('owner');
        $member = $this->makeUser('target@example.test');
        $target = $this->attach($org, $member, 'member');
        $ownerMembership = OrganizationMember::where('organization_id', $org->id)->where('user_id', $owner->id)->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $org->id])
            ->patchJson("/organization/members/{$target->id}", ['role' => 'owner'])
            ->assertUnprocessable();

        $this->actingAs($owner)
            ->withSession(['active_organization_id' => $org->id])
            ->patchJson("/organization/members/{$ownerMembership->id}", ['status' => 'suspended'])
            ->assertStatus(422);
    }

    public function test_member_cannot_modify_own_membership_or_cross_tenant_member(): void
    {
        [$orgA, $ownerA] = $this->orgWithUser('owner', 'org-a');
        [$orgB, $ownerB] = $this->orgWithUser('owner', 'org-b');
        $adminA = $this->makeUser('admina@example.test');
        $adminAMembership = $this->attach($orgA, $adminA, 'admin');
        $targetB = OrganizationMember::where('organization_id', $orgB->id)->where('user_id', $ownerB->id)->firstOrFail();

        $this->actingAs($adminA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->patchJson("/organization/members/{$adminAMembership->id}", ['role' => 'member'])
            ->assertStatus(422);

        $this->actingAs($ownerA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->patchJson("/organization/members/{$targetB->id}", ['status' => 'suspended'])
            ->assertNotFound();
    }

    private function attach(Organization $org, User $user, string $role): OrganizationMember
    {
        return OrganizationMember::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function makeUser(?string $email = null): User
    {
        $user = User::create([
            'name' => 'User',
            'email' => $email ?? uniqid('u').'@example.test',
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
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
        $user = $this->makeUser(uniqid('owner').'@example.test');
        $this->attach($org, $user, $role);
        return [$org, $user];
    }
}
