<?php

namespace Tests\Feature;

use App\Models\GovernanceRequest;
use App\Models\GroupAuthority;
use App\Models\GroupStaffApprovedPhone;
use App\Models\User;
use App\Services\GovernanceApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupGovernanceOtpTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $label): User
    {
        $user = User::create([
            'name' => $label,
            'email' => strtolower($label).'-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $user->markEmailAsVerified();

        return $user;
    }

    private function authority(User $user, string $authority): void
    {
        GroupAuthority::create([
            'user_id' => $user->id,
            'authority' => $authority,
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);
    }

    public function test_only_group_security_authority_can_pre_register_group_staff_phone(): void
    {
        $securityOfficer = $this->user('Security Officer');
        $staff = $this->user('Group Staff');
        $this->authority($securityOfficer, 'GROUP_SECURITY_OFFICER');
        $this->authority($staff, 'GROUP_AUDITOR');

        $this->actingAs($securityOfficer)
            ->postJson('/group/staff-security/approved-phones', [
                'user_id' => $staff->id,
                'phone' => '+254712345678',
            ])
            ->assertCreated()
            ->assertJsonPath('phone_last_four', '5678');

        $this->assertDatabaseHas('group_staff_approved_phones', [
            'user_id' => $staff->id,
            'phone_e164' => '+254712345678',
            'approval_method' => 'staff_approval',
            'status' => 'active',
        ]);
    }

    public function test_group_staff_cannot_self_approve_their_phone(): void
    {
        $staff = $this->user('Group Staff');
        $this->authority($staff, 'GROUP_SECURITY_OFFICER');

        $this->actingAs($staff)
            ->postJson('/group/staff-security/approved-phones', [
                'user_id' => $staff->id,
                'phone' => '+254712345678',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('group_staff_approved_phones', 0);
    }

    public function test_governance_decision_requires_group_otp_and_consumes_it_once(): void
    {
        $requester = $this->user('Requester');
        $subject = $this->user('Appointee');
        $director = $this->user('Director');
        $securityOfficer = $this->user('Security Officer');
        $this->authority($director, 'GROUP_DIRECTOR');
        $this->authority($securityOfficer, 'GROUP_SECURITY_OFFICER');

        GroupStaffApprovedPhone::create([
            'user_id' => $director->id,
            'phone_e164' => '+254712345678',
            'approved_by' => $securityOfficer->id,
            'approved_at' => now(),
            'status' => 'active',
        ]);

        $request = app(GovernanceApprovalService::class)->openRequest(
            $requester,
            $subject,
            'DEPARTMENT_HEAD',
            'group',
            'nexora',
            'Appointment to the product department',
            [[
                'approver_user_id' => $director->id,
                'approval_level' => 1,
                'required_authority' => 'GROUP_DIRECTOR',
            ]],
        );
        $approvalId = $request->approvals[0]->id;
        $purpose = 'governance.approval.decide.'.$approvalId;

        config([
            'services.nexora_group_sms.url' => 'https://sms.example.test/send',
            'services.nexora_group_sms.token' => 'test-token',
        ]);

        $sentCode = null;
        Http::fake(function ($httpRequest) use (&$sentCode) {
            preg_match('/code: ([0-9]{6})/', $httpRequest['message'], $matches);
            $sentCode = $matches[1] ?? null;

            return Http::response(['accepted' => true], 200);
        });

        $this->actingAs($director)
            ->postJson('/group/staff-security/otp/send', ['purpose' => $purpose])
            ->assertOk()
            ->assertJsonPath('message', 'Code sent to the pre-approved group staff number.');

        $challengeId = \App\Models\GroupStaffOtpChallenge::where('user_id', $director->id)->value('id');
        $this->assertNotNull($sentCode);

        $this->postJson('/group/staff-security/otp/verify', [
            'challenge_id' => $challengeId,
            'code' => $sentCode,
        ])->assertOk();

        $this->postJson('/group/governance/approvals/'.$approvalId.'/decision', [
            'decision' => 'approved',
        ])->assertOk()->assertJsonPath('request.status', 'approved');

        $this->assertDatabaseHas('group_staff_otp_challenges', [
            'id' => $challengeId,
        ]);
        $this->assertNotNull(
            \App\Models\GroupStaffOtpChallenge::findOrFail($challengeId)->consumed_at
        );
        $this->assertDatabaseHas('group_authorities', [
            'user_id' => $subject->id,
            'authority' => 'DEPARTMENT_HEAD',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
        ]);

        $this->postJson('/group/governance/approvals/'.$approvalId.'/decision', [
            'decision' => 'approved',
        ])->assertNotFound();
    }

    public function test_group_otp_requires_https_provider_and_active_group_authority(): void
    {
        $director = $this->user('Director');
        $this->authority($director, 'GROUP_DIRECTOR');
        GroupStaffApprovedPhone::create([
            'user_id' => $director->id,
            'phone_e164' => '+254712345678',
            'approved_by' => $director->id,
            'approved_at' => now(),
            'status' => 'active',
        ]);

        config(['services.nexora_group_sms.url' => 'http://sms.example.test/send']);

        $this->actingAs($director)
            ->postJson('/group/staff-security/otp/send', [
                'purpose' => 'governance.approval.decide.1',
            ])
            ->assertStatus(503);
    }
}
