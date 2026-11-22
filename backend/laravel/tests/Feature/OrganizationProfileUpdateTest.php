<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_organization_profile_fields(): void
    {
        [$org, $admin] = $this->orgWithUser('admin');

        $this->actingAs($admin)
            ->withSession(['active_organization_id' => $org->id])
            ->patchJson('/organization/profile', [
                'name' => 'NetPulse Updated',
                'timezone' => 'Africa/Nairobi',
                'country_code' => 'ke',
                'slug' => 'should-not-change',
            ])
            ->assertOk()
            ->assertJsonPath('organization.name', 'NetPulse Updated')
            ->assertJsonPath('organization.country_code', 'KE')
            ->assertJsonPath('organization.slug', $org->slug);

        $this->assertDatabaseHas('organizations', [
            'id' => $org->id,
            'name' => 'NetPulse Updated',
            'country_code' => 'KE',
            'slug' => $org->slug,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'action' => 'organization.profile_updated',
        ]);
    }

    public function test_member_cannot_update_organization_profile(): void
    {
        [$org, $member] = $this->orgWithUser('member');

        $this->actingAs($member)
            ->withSession(['active_organization_id' => $org->id])
            ->patchJson('/organization/profile', ['name' => 'Nope'])
            ->assertForbidden();
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function orgWithUser(string $role): array
    {
        $org = Organization::create([
            'name' => 'Org',
            'slug' => 'org-'.uniqid(),
            'type' => 'isp',
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
