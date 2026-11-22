<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\EngineerAccessGrant;
use App\Models\EngineerSession;
use App\Models\Organization;
use App\Models\User;
use App\Services\EngineerSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EngineerSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_token_is_returned_once_and_only_hash_is_stored(): void
    {
        [$actor, $organization] = $this->makeTenant();
        $result = app(EngineerSessionService::class)->start($actor, $organization);

        $this->assertSame(64, strlen($result['token']));
        $this->assertNotSame($result['token'], $result['session']->token_hash);
        $this->assertSame(hash('sha256', $result['token']), $result['session']->token_hash);
        $this->assertArrayNotHasKey('token_hash', $result['session']->toArray());
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $actor->id,
            'action' => 'engineer.session_started',
        ]);
        $this->assertDatabaseMissing('audit_logs', ['metadata' => json_encode(['token' => $result['token']])]);
    }

    public function test_membership_alone_does_not_authorize_engineer_access(): void
    {
        $actor = $this->makeUser();
        $organization = $this->makeOrganization();
        $actor->organizations()->attach($organization->id, [
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        try {
            app(EngineerSessionService::class)->start($actor, $organization);
            $this->fail('A tenant owner without an engineer grant must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dual-approved engineer access grant', $exception->getMessage());
        }

        $this->assertDatabaseCount('engineer_sessions', 0);
    }

    public function test_session_cannot_be_started_for_an_unrelated_tenant(): void
    {
        $actor = $this->makeUser();
        $organization = $this->makeOrganization();

        try {
            app(EngineerSessionService::class)->start($actor, $organization);
            $this->fail('Expected unrelated tenant to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('membership', $exception->getMessage());
        }

        $this->assertDatabaseCount('engineer_sessions', 0);
    }

    public function test_only_one_active_session_per_actor_and_tenant_is_allowed(): void
    {
        [$actor, $organization] = $this->makeTenant();
        $service = app(EngineerSessionService::class);
        $service->start($actor, $organization);

        $this->expectException(RuntimeException::class);
        $service->start($actor, $organization);
    }

    public function test_deactivation_requires_exact_command_and_session_owner(): void
    {
        [$actor, $organization] = $this->makeTenant();
        $otherActor = $this->makeUser();
        $service = app(EngineerSessionService::class);
        $result = $service->start($actor, $organization);

        try {
            $service->deactivate($otherActor, $organization, $result['token'], EngineerSessionService::DEACTIVATE_COMMAND);
            $this->fail('A different actor must not revoke this session.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('No matching', $exception->getMessage());
        }

        try {
            $service->deactivate($actor, $organization, $result['token'], 'GOLD MODE');
            $this->fail('An inexact command must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not recognised', $exception->getMessage());
        }

        $service->deactivate($actor, $organization, $result['token'], EngineerSessionService::DEACTIVATE_COMMAND);

        $this->assertNotNull(EngineerSession::firstOrFail()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $actor->id,
            'action' => 'engineer.session_deactivated',
        ]);
    }

    public function test_activity_renews_idle_expiry_but_never_absolute_expiry(): void
    {
        [$actor, $organization] = $this->makeTenant();
        $service = app(EngineerSessionService::class);
        $result = $service->start($actor, $organization, 60, 120);

        $this->travel(50)->seconds();
        $touched = $service->touch($actor, $organization, $result['token'], 60);

        $this->assertTrue($touched->idle_expires_at->greaterThan($touched->started_at->copy()->addSeconds(60)));
        $this->assertEquals($result['session']->absolute_expires_at, $touched->absolute_expires_at);

        $this->travel(71)->seconds();

        try {
            $service->touch($actor, $organization, $result['token'], 60);
            $this->fail('An absolutely expired session must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('expired', $exception->getMessage());
        }

        $this->assertNotNull(EngineerSession::firstOrFail()->revoked_at);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $actor->id,
            'action' => 'engineer.session_expired',
        ]);
    }

    public function test_revoking_engineer_grant_revokes_the_active_session_on_next_check(): void
    {
        [$actor, $organization] = $this->makeTenant();
        $service = app(EngineerSessionService::class);
        $result = $service->start($actor, $organization);

        EngineerAccessGrant::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $actor->id)
            ->update(['revoked_at' => now(), 'status' => 'revoked']);

        try {
            $service->touch($actor, $organization, $result['token']);
            $this->fail('A revoked engineer grant must invalidate the session.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('missing, expired, or revoked', $exception->getMessage());
        }

        $this->assertSame('engineer_grant_missing_or_expired', EngineerSession::firstOrFail()->revocation_reason);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $actor->id,
            'action' => 'engineer.session_revoked',
        ]);
    }

    public function test_timeout_configuration_is_bounded(): void
    {
        [$actor, $organization] = $this->makeTenant();

        $this->expectException(RuntimeException::class);
        app(EngineerSessionService::class)->start($actor, $organization, 10, 20);
    }

    private function makeTenant(): array
    {
        $actor = $this->makeUser();
        $organization = $this->makeOrganization();
        $actor->organizations()->attach($organization->id, [
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $grantor = $this->makeUser();
        $approver = $this->makeUser();
        EngineerAccessGrant::create([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'granted_by_user_id' => $grantor->id,
            'approved_by_user_id' => $approver->id,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
            'scope' => ['devices' => ['read', 'diagnostics']],
        ]);

        return [$actor, $organization];
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Engineer Test',
            'email' => 'engineer-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Engineer Tenant',
            'slug' => 'engineer-'.uniqid(),
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
    }
}
