<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_manage_members_can_list_tenant_scoped_audit_logs(): void
    {
        [$orgA, $adminA] = $this->orgWithUser('admin', 'org-a');
        [$orgB, $adminB] = $this->orgWithUser('admin', 'org-b');

        AuditLog::create([
            'organization_id' => $orgA->id,
            'actor_user_id' => $adminA->id,
            'action' => 'organization.member_updated',
            'subject_type' => 'App\\Models\\OrganizationMember',
            'subject_id' => '1',
            'metadata' => ['before' => ['role' => 'member'], 'after' => ['role' => 'manager']],
        ]);
        AuditLog::create([
            'organization_id' => $orgB->id,
            'actor_user_id' => $adminB->id,
            'action' => 'organization.member_updated',
            'subject_type' => 'App\\Models\\OrganizationMember',
            'subject_id' => '2',
            'metadata' => ['secret' => 'should-not-leak'],
        ]);

        $this->actingAs($adminA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->getJson('/organization/audit-logs?action=organization.member_updated')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'organization.member_updated')
            ->assertJsonPath('data.0.actor.id', $adminA->id)
            ->assertJsonMissing(['secret' => 'should-not-leak']);
    }

    public function test_non_manager_cannot_view_audit_logs(): void
    {
        [$org, $member] = $this->orgWithUser('member');

        $this->actingAs($member)
            ->withSession(['active_organization_id' => $org->id])
            ->getJson('/organization/audit-logs')
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
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
        $user = User::create([
            'name' => 'User',
            'email' => uniqid('u').'@example.test',
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
