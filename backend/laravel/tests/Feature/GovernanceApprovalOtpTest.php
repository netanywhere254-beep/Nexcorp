<?php

namespace Tests\Feature;

use App\Models\ApprovalOtpChallenge;
use App\Models\AuthorizedApprovalPhone;
use App\Models\GovernanceApproval;
use App\Models\GovernanceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GovernanceApprovalOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.approval_sms.url' => 'https://sms.example.test/messages',
            'services.approval_sms.token' => 'test-token',
            'services.approval_sms.ttl_minutes' => 5,
        ]);
    }

    public function test_only_the_assigned_approver_with_a_preapproved_number_can_request_an_otp(): void
    {
        Http::fake();
        [, $approval, $approver] = $this->approvalFixture();
        $this->registerPhone($approver);

        $this->actingAs($approver)
            ->postJson('/governance/approvals/'.$approval->id.'/otp')
            ->assertAccepted()
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('destination', '••••••1234');

        Http::assertSent(fn ($request) => $request->url() === 'https://sms.example.test/messages'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['to'] === '+2547000001234');
    }

    public function test_an_unregistered_phone_cannot_receive_an_approval_otp(): void
    {
        Http::fake();
        [, $approval, $approver] = $this->approvalFixture();

        $this->actingAs($approver)
            ->postJson('/governance/approvals/'.$approval->id.'/otp')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_another_user_cannot_send_or_confirm_an_approval(): void
    {
        Http::fake();
        [, $approval] = $this->approvalFixture();
        $other = $this->makeUser('other');

        $this->actingAs($other)
            ->postJson('/governance/approvals/'.$approval->id.'/otp')
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_correct_otp_is_single_use_and_completes_request(): void
    {
        [$request, $approval, $approver] = $this->approvalFixture();
        $phone = $this->registerPhone($approver);
        $code = '481205';
        $challenge = $this->createChallenge($approval, $approver, $phone, $code);

        $this->actingAs($approver)
            ->postJson('/governance/approvals/'.$approval->id.'/confirm', [
                'otp' => $code,
                'decision' => 'approved',
                'comment' => 'Reviewed and approved.',
            ])->assertOk()->assertJsonPath('status', 'approved');

        $this->assertSame('approved', $approval->fresh()->decision);
        $this->assertSame('approved', $request->fresh()->status);
        $this->assertNotNull($challenge->fresh()->consumed_at);

        $this->actingAs($approver)
            ->postJson('/governance/approvals/'.$approval->id.'/confirm', [
                'otp' => $code,
                'decision' => 'approved',
            ])->assertStatus(409);
    }

    public function test_wrong_otp_increments_attempts_and_never_approves(): void
    {
        [, $approval, $approver] = $this->approvalFixture();
        $phone = $this->registerPhone($approver);
        $challenge = $this->createChallenge($approval, $approver, $phone, '481205');

        $this->actingAs($approver)
            ->postJson('/governance/approvals/'.$approval->id.'/confirm', [
                'otp' => '000000',
                'decision' => 'approved',
            ])->assertUnprocessable();

        $this->assertSame(1, $challenge->fresh()->attempts);
        $this->assertSame('pending', $approval->fresh()->decision);
    }

    public function test_requester_cannot_approve_their_own_request(): void
    {
        [, $approval, $requester] = $this->approvalFixture(requesterIsApprover: true);
        $this->registerPhone($requester);

        $this->actingAs($requester)
            ->postJson('/governance/approvals/'.$approval->id.'/otp')
            ->assertForbidden();
    }


    public function test_expired_otp_cannot_decide_a_request(): void
    {
        [$request, $approval, $approver] = $this->approvalFixture();
        $phone = $this->registerPhone($approver);
        $challenge = $this->createChallenge($approval, $approver, $phone, '481205');
        $challenge->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($approver)
            ->postJson('/governance/approvals/'.$approval->id.'/confirm', [
                'otp' => '481205',
                'decision' => 'approved',
            ])->assertUnprocessable();

        $this->assertSame('pending', $approval->fresh()->decision);
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_rejection_with_valid_otp_closes_the_governance_request(): void
    {
        [$request, $approval, $approver] = $this->approvalFixture();
        $phone = $this->registerPhone($approver);
        $this->createChallenge($approval, $approver, $phone, '481205');

        $this->actingAs($approver)
            ->postJson('/governance/approvals/'.$approval->id.'/confirm', [
                'otp' => '481205',
                'decision' => 'rejected',
                'comment' => 'Insufficient justification.',
            ])->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertSame('rejected', $approval->fresh()->decision);
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $approver->id,
            'action' => 'governance.approval_rejected',
        ]);
    }

    private function approvalFixture(bool $requesterIsApprover = false): array
    {
        $requester = $this->makeUser('requester');
        $approver = $requesterIsApprover ? $requester : $this->makeUser('approver');

        $request = GovernanceRequest::create([
            'public_id' => (string) Str::uuid(),
            'requested_by' => $requester->id,
            'subject_user_id' => $approver->id,
            'requested_authority' => 'DEPARTMENT_HEAD',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'pending',
            'business_reason' => 'Test governance approval.',
            'expires_at' => now()->addDay(),
        ]);

        $approval = GovernanceApproval::create([
            'governance_request_id' => $request->id,
            'approver_user_id' => $approver->id,
            'approval_level' => 1,
            'required_authority' => 'DEPARTMENT_HEAD',
            'decision' => 'pending',
        ]);

        return [$request, $approval, $approver];
    }

    private function registerPhone(User $user): AuthorizedApprovalPhone
    {
        return AuthorizedApprovalPhone::create([
            'user_id' => $user->id,
            'phone_hash' => hash('sha256', '+2547000001234'),
            'phone_e164' => '+2547000001234',
            'status' => 'active',
            'verified_at' => now(),
            'approved_by' => $user->id,
        ]);
    }

    private function createChallenge(
        GovernanceApproval $approval,
        User $approver,
        AuthorizedApprovalPhone $phone,
        string $code
    ): ApprovalOtpChallenge {
        return ApprovalOtpChallenge::create([
            'governance_approval_id' => $approval->id,
            'authorized_approval_phone_id' => $phone->id,
            'approver_user_id' => $approver->id,
            'otp_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    private function makeUser(string $prefix): User
    {
        static $counter = 0;
        $counter++;

        return User::create([
            'name' => ucfirst($prefix),
            'email' => $prefix.'-'.$counter.'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ]);
    }
}
