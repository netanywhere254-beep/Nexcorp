<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;


    public function test_public_registration_ignores_privilege_and_organization_fields(): void
    {
        RateLimiter::clear('nexora-register:127.0.0.1');

        $this->postJson('/auth/register', [
            'name' => 'New Customer',
            'email' => 'new-customer@example.test',
            'phone' => '+254700000000',
            'password' => 'VerySecurePassphrase42!',
            'password_confirmation' => 'VerySecurePassphrase42!',
            'role' => 'admin',
            'is_admin' => true,
            'organization_id' => 999999,
            'status' => 'suspended',
        ])->assertCreated()
            ->assertJsonPath('user.email', 'new-customer@example.test')
            ->assertJsonMissingPath('user.role')
            ->assertJsonMissingPath('user.password');

        $user = User::where('email', 'new-customer@example.test')->firstOrFail();

        $this->assertSame('active', $user->status);\n        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertSame(0, $user->organizations()->count());
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => null,
            'actor_user_id' => $user->id,
            'action' => 'user.registered',
        ]);
    }

    public function test_public_registration_rejects_weak_or_unconfirmed_passwords(): void
    {
        RateLimiter::clear('nexora-register:127.0.0.1');

        $this->postJson('/auth/register', [
            'name' => 'Weak Password User',
            'email' => 'weak-password@example.test',
            'password' => 'weak123',
            'password_confirmation' => 'different123',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('users', [
            'email' => 'weak-password@example.test',
        ]);
    }

    public function test_public_registration_is_rate_limited(): void
    {
        RateLimiter::clear('nexora-register:127.0.0.1');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $email = 'registration-'.$attempt.'@example.test';

            $this->postJson('/auth/register', [
                'name' => 'Rate Limited User',
                'email' => $email,
                'password' => 'VerySecurePassphrase42!',
                'password_confirmation' => 'VerySecurePassphrase42!',
            ])->assertCreated();
        }

        $this->postJson('/auth/register', [
            'name' => 'Sixth User',
            'email' => 'registration-six@example.test',
            'password' => 'VerySecurePassphrase42!',
            'password_confirmation' => 'VerySecurePassphrase42!',
        ])->assertStatus(429);
    }

    public function test_authenticated_profile_requires_a_session(): void
    {
        $this->getJson('/auth/me')->assertUnauthorized();
    }

    public function test_active_user_can_log_in_and_receives_only_active_memberships(): void
    {
        $user = $this->makeUser();
        $active = $this->makeOrganization('active-org');
        $inactive = $this->makeOrganization('inactive-org');

        $user->organizations()->attach($active->id, [
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->organizations()->attach($inactive->id, [
            'role' => 'member',
            'status' => 'suspended',
            'joined_at' => now(),
        ]);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'Correct-Horse-42!',
        ])->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonCount(1, 'user.organizations')
            ->assertJsonPath('user.organizations.0.slug', 'active-org');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = $this->makeUser(['status' => 'suspended']);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'Correct-Horse-42!',
        ])->assertUnprocessable();
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        $email = 'blocked@example.test';
        RateLimiter::clear('nexora-login:'.$email.'|127.0.0.1');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/auth/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/auth/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ])->assertStatus(429)
            ->assertJsonStructure(['message', 'retry_after_seconds']);
    }

    public function test_member_can_select_an_active_organization_and_selection_is_audited(): void
    {
        $user = $this->makeUser();
        $organization = $this->makeOrganization('tenant-one');
        $user->organizations()->attach($organization->id, [
            'role' => 'admin',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user)->postJson('/auth/organization', [
            'organization_id' => $organization->id,
        ])->assertOk()
            ->assertJsonPath('active_organization.id', $organization->id)
            ->assertJsonPath('active_organization.role', 'admin');

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'action' => 'organization.selected',
        ]);
        $this->assertSame(
            $organization->id,
            session('active_organization_id')
        );
    }

    public function test_user_cannot_select_an_inactive_or_unrelated_organization(): void
    {
        $user = $this->makeUser();
        $organization = $this->makeOrganization('suspended-tenant');
        $user->organizations()->attach($organization->id, [
            'role' => 'member',
            'status' => 'suspended',
            'joined_at' => now(),
        ]);

        $this->actingAs($user)->postJson('/auth/organization', [
            'organization_id' => $organization->id,
        ])->assertForbidden();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->withSession(['active_organization_id' => 123])
            ->postJson('/auth/logout')
            ->assertOk();

        $this->getJson('/auth/me')->assertUnauthorized();
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => 'user-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ], $overrides));
    }

    private function makeOrganization(string $slug): Organization
    {
        return Organization::create([
            'name' => 'Test '.ucfirst($slug),
            'slug' => $slug,
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
    }
}
