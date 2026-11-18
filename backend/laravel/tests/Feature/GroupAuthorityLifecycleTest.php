<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GroupAuthority;
use App\Models\GroupStaffApprovedPhone;
use App\Models\GroupStaffOtpChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GroupAuthorityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function authority(User $user, string $authority, string $status = 'active'): GroupAuthority
    {
        return GroupAuthority::create([
            'user_id' => $user->id,
            'authority' => $authority,
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => $status,
            'starts_at' => now()->subMinute(),
        ]);
    }

    private function verifiedOtp(User $ceo, string $purpose): array
    {
        $phone = GroupStaffApprovedPhone::create([
            'user_id' => $ceo->id,
            'phone_e164' => '+254712345678',
            'approved_by' => $ceo->id,
            'approved_at' => now(),
            'status' => 'active',
        ]);
        $challenge = GroupStaffOtpChallenge::create([
            'user_id' => $ceo->id,
            'approved_phone_id' => $phone->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);

        return [$challenge, [
            'purpose' => $purpose,
            'user_id' => $ceo->id,
            'verified_at' => now()->timestamp,
            'challenge_id' => $challenge->id,
        ]];
    }

    public function test_only_ceo_with_exact_purpose_otp_can_revoke_group_authority(): void
    {
        $ceo = $this->user('Group CEO');
        $target = $this->user('Department Head');
        $ceoAuthority = $this->authority($ceo, 'GROUP_CEO');
        $targetAuthority = $this->authority($target, 'DEPARTMENT_HEAD');
        $purpose = 'group.authority.revoke.'.$targetAuthority->id;
        [$challenge, $session] = $this->verifiedOtp($ceo, $purpose);

        $this->actingAs($ceo)->withSession(['nexora_group_staff_otp' => $session])
            ->deleteJson('/group/governance/authorities/'.$targetAuthority->id)
            ->assertOk();

        $this->assertDatabaseHas('group_authorities', [
            'id' => $targetAuthority->id,
            'status' => 'revoked',
        ]);
        $this->assertNotNull($challenge->fresh()->consumed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'group.authority.revoked']);
        $this->assertDatabaseHas('group_authorities', [
            'id' => $ceoAuthority->id,
            'status' => 'active',
        ]);
    }

    public function test_non_ceo_cannot_revoke_group_authority(): void
    {
        $director = $this->user('Director');
        $target = $this->user('Auditor');
        $this->authority($director, 'GROUP_DIRECTOR');
        $targetAuthority = $this->authority($target, 'GROUP_AUDITOR');

        $this->actingAs($director)
            ->deleteJson('/group/governance/authorities/'.$targetAuthority->id)
            ->assertForbidden();

        $this->assertDatabaseHas('group_authorities', [
            'id' => $targetAuthority->id,
            'status' => 'active',
        ]);
    }

    public function test_last_active_ceo_cannot_be_revoked_even_with_otp(): void
    {
        $ceo = $this->user('Group CEO');
        $target = $this->user('Other CEO');
        $this->authority($ceo, 'GROUP_CEO');
        $targetAuthority = $this->authority($target, 'GROUP_CEO');
        $purpose = 'group.authority.revoke.'.$targetAuthority->id;
        [, $session] = $this->verifiedOtp($ceo, $purpose);

        $this->actingAs($ceo)->withSession(['nexora_group_staff_otp' => $session])
            ->deleteJson('/group/governance/authorities/'.$targetAuthority->id)
            ->assertStatus(409);

        $this->assertDatabaseHas('group_authorities', [
            'id' => $targetAuthority->id,
            'status' => 'active',
        ]);
    }

    public function test_one_time_cli_bootstrap_requires_secret_and_never_repeats(): void
    {
        $user = $this->user('Founding CEO');
        config(['services.nexora_group_ceo_bootstrap_key' => 'one-time-secret']);

        $this->artisan('nexora:bootstrap-group-ceo', ['email' => $user->email])
            ->expectsQuestion('One-time bootstrap key', 'one-time-secret')
            ->assertExitCode(0);

        $this->assertDatabaseHas('group_authorities', [
            'user_id' => $user->id,
            'authority' => 'GROUP_CEO',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'group.ceo.initial_bootstrap']);

        $another = $this->user('Second CEO Attempt');
        $this->artisan('nexora:bootstrap-group-ceo', ['email' => $another->email])
            ->expectsQuestion('One-time bootstrap key', 'one-time-secret')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('group_authorities', [
            'user_id' => $another->id,
            'authority' => 'GROUP_CEO',
        ]);
    }
}
