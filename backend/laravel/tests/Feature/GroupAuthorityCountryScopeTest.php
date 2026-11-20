<?php

namespace Tests\\Feature;

use App\\Models\\GroupAuthority;
use App\\Models\\GroupStaffApprovedPhone;
use App\\Models\\GroupStaffOtpChallenge;
use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Hash;
use Tests\\TestCase;

class GroupAuthorityCountryScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_ceo_can_appoint_country_scope_with_fresh_staff_otp(): void
    {
        $ceo = User::create([
            'name' => 'Group CEO',
            'email' => 'country-scope-ceo@example.test',
            'phone' => '+254711000201',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);
        $ceo->forceFill(['email_verified_at' => now()])->save();

        GroupAuthority::create([
            'user_id' => $ceo->id,
            'authority' => 'GROUP_CEO',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);

        $reviewer = User::create([
            'name' => 'Country Reviewer',
            'email' => 'country-reviewer@example.test',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);
        $reviewer->forceFill(['email_verified_at' => now()])->save();

        $targetAppointment = GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);

        $phone = GroupStaffApprovedPhone::create([
            'user_id' => $ceo->id,
            'phone_e164' => $ceo->phone,
            'approved_by' => $ceo->id,
            'approval_method' => 'staff_approval',
            'approved_at' => now(),
            'status' => 'active',
        ]);

        $purpose = 'group.authority.country_scope.appoint.'.$targetAppointment->id.'.KE';
        $challenge = GroupStaffOtpChallenge::create([
            'user_id' => $ceo->id,
            'approved_phone_id' => $phone->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);

        $session = ['nexora_group_staff_otp' => [
            'challenge_id' => $challenge->id,
            'user_id' => $ceo->id,
            'purpose' => $purpose,
            'verified_at' => now()->timestamp,
        ]];

        $this->actingAs($ceo)->withSession($session)
            ->postJson('/group/staff-security/'.$targetAppointment->id.'/country-scope', [
                'country_code' => 'ke',
                'expires_at' => now()->addMonths(3)->toISOString(),
            ])
            ->assertCreated()
            ->assertJsonPath('appointment.country_code', 'KE')
            ->assertJsonPath('appointment.authority', 'GROUP_SECURITY_OFFICER');

        $this->assertDatabaseHas('group_authorities', [
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'country',
            'scope_key' => 'KE',
            'status' => 'active',
            'appointed_by' => $ceo->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $ceo->id,
            'action' => 'group.authority.country_scope.appointed',
        ]);

        $countryAppointment = GroupAuthority::query()
            ->where('user_id', $reviewer->id)
            ->where('scope_type', 'country')
            ->where('scope_key', 'KE')
            ->firstOrFail();
        $revokePurpose = 'group.authority.country_scope.revoke.'.$countryAppointment->id;
        $revokeChallenge = GroupStaffOtpChallenge::create([
            'user_id' => $ceo->id,
            'approved_phone_id' => $phone->id,
            'purpose' => $revokePurpose,
            'code_hash' => Hash::make('654321'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);
        $revokeSession = ['nexora_group_staff_otp' => [
            'challenge_id' => $revokeChallenge->id,
            'user_id' => $ceo->id,
            'purpose' => $revokePurpose,
            'verified_at' => now()->timestamp,
        ]];

        $this->actingAs($ceo)->withSession($revokeSession)
            ->deleteJson('/group/staff-security/'.$countryAppointment->id.'/country-scope')
            ->assertOk();

        $this->assertDatabaseHas('group_authorities', [
            'id' => $countryAppointment->id,
            'status' => 'revoked',
        ]);
    }
}
