<?php

namespace Tests\Feature;

use App\Models\GroupAuthority;
use App\Models\GroupStaffApprovedPhone;
use App\Models\GroupStaffOtpChallenge;
use App\Models\TraceAccessApplication;
use App\Models\TraceCountryAccessGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoopzaTraceAccessApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(string $email = 'applicant@example.test'): User
    {
        $user = User::create([
            'name' => 'Trace Applicant',
            'email' => $email,
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user;
    }

    public function test_professional_application_is_pending_and_does_not_create_authority_appointment(): void
    {
        $user = $this->verifiedUser();

        $response = $this->actingAs($user)->postJson('/trace-find/access-applications', [
            'requested_role' => 'professional_investigator',
            'country_code' => 'ke',
            'organisation_name' => 'Kisii Independent Investigations',
            'organisation_registration' => 'REG-KE-12345',
            'credentials_summary' => 'Licensed professional investigator with current credentials and references.',
            'evidence' => UploadedFile::fake()->create('credentials.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('application.status', 'pending_review')
            ->assertJsonPath('application.country_code', 'KE');

        $this->assertDatabaseHas('trace_access_applications', [
            'user_id' => $user->id,
            'requested_role' => 'professional_investigator',
            'country_code' => 'KE',
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseMissing('group_authorities', ['user_id' => $user->id]);
    }

    public function test_authority_application_requires_organisation_and_credentials(): void
    {
        $user = $this->verifiedUser('authority@example.test');

        $this->actingAs($user)->postJson('/trace-find/access-applications', [
            'requested_role' => 'authority',
            'country_code' => 'KE',
            'evidence' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('trace_access_applications', ['user_id' => $user->id]);
    }

    public function test_volunteer_application_can_be_submitted_without_claiming_authority(): void
    {
        $user = $this->verifiedUser('volunteer@example.test');

        $this->actingAs($user)->postJson('/trace-find/access-applications', [
            'requested_role' => 'volunteer',
            'country_code' => 'KE',
            'evidence' => UploadedFile::fake()->create('volunteer-reference.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('application.requested_role', 'volunteer')
            ->assertJsonPath('application.status', 'pending_review');

        $this->assertDatabaseMissing('group_authorities', ['user_id' => $user->id]);
    }

    public function test_unappointed_user_cannot_access_vetting_queue(): void
    {
        $user = $this->verifiedUser('outsider@example.test');

        $this->actingAs($user)->getJson('/trace-find/review/access-applications')
            ->assertForbidden();
    }


    public function test_vetting_approval_requires_a_separate_country_scoped_grant_with_otp(): void
    {
        $reviewer = $this->verifiedUser('reviewer@example.test');
        $reviewer->forceFill(['phone' => '+254711000101'])->save();
        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);
        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'country',
            'scope_key' => 'KE',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);
        $phone = GroupStaffApprovedPhone::create([
            'user_id' => $reviewer->id,
            'phone_e164' => $reviewer->phone,
            'approved_by' => $reviewer->id,
            'approval_method' => 'staff_approval',
            'approved_at' => now(),
            'status' => 'active',
        ]);
        $purpose = 'loopza.trace.access_applications.grant.501';
        $challenge = GroupStaffOtpChallenge::create([
            'user_id' => $reviewer->id,
            'approved_phone_id' => $phone->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);
        $applicant = $this->verifiedUser('investigator@example.test');
        $application = TraceAccessApplication::create([
            'user_id' => $applicant->id,
            'requested_role' => 'professional_investigator',
            'country_code' => 'KE',
            'organisation_name' => 'Independent Investigations',
            'organisation_registration' => 'REG-KE-555',
            'credentials_summary' => 'Verified professional credentials supplied for review.',
            'evidence_path' => 'trace-find/access-applications/evidence.pdf',
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => 'Credentials verified.',
        ]);
        $purpose = 'loopza.trace.access_applications.grant.'.$application->id;
        $challenge->update(['purpose' => $purpose]);

        $session = ['nexora_group_staff_otp' => [
            'challenge_id' => $challenge->id,
            'user_id' => $reviewer->id,
            'purpose' => $purpose,
            'verified_at' => now()->timestamp,
        ]];

        $this->actingAs($reviewer)->withSession($session)
            ->postJson('/trace-find/review/access-applications/'.$application->id.'/grant', [
                'permissions' => ['cases.read', 'cases.match.submit'],
                'region_code' => 'KE-17',
                'expires_at' => now()->addMonths(3)->toISOString(),
            ])
            ->assertCreated()
            ->assertJsonPath('grant.country_code', 'KE')
            ->assertJsonPath('grant.region_code', 'KE-17')
            ->assertJsonPath('grant.role', 'professional_investigator');

        $this->assertDatabaseHas('trace_country_access_grants', [
            'user_id' => $applicant->id,
            'source_application_id' => $application->id,
            'access_role' => 'professional_investigator',
            'country_code' => 'KE',
            'region_code' => 'KE-17',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $reviewer->id,
            'action' => 'loopza.trace_country_access.granted',
        ]);
    }

    public function test_volunteer_cannot_be_granted_private_case_permissions(): void
    {
        $user = $this->verifiedUser('volunteer-application@example.test');
        $reviewer = $this->verifiedUser('reviewer-denied@example.test');
        $reviewer->forceFill(['phone' => '+254711000102'])->save();
        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);
        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'country',
            'scope_key' => 'KE',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);
        $phone = GroupStaffApprovedPhone::create([
            'user_id' => $reviewer->id,
            'phone_e164' => $reviewer->phone,
            'approved_by' => $reviewer->id,
            'approval_method' => 'staff_approval',
            'approved_at' => now(),
            'status' => 'active',
        ]);
        $application = TraceAccessApplication::create([
            'user_id' => $user->id,
            'requested_role' => 'volunteer',
            'country_code' => 'KE',
            'evidence_path' => 'trace-find/access-applications/volunteer.pdf',
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => 'Volunteer vetting approved.',
        ]);
        $purpose = 'loopza.trace.access_applications.grant.'.$application->id;
        $challenge = GroupStaffOtpChallenge::create([
            'user_id' => $reviewer->id,
            'approved_phone_id' => $phone->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);
        $session = ['nexora_group_staff_otp' => [
            'challenge_id' => $challenge->id,
            'user_id' => $reviewer->id,
            'purpose' => $purpose,
            'verified_at' => now()->timestamp,
        ]];

        $this->actingAs($reviewer)->withSession($session)
            ->postJson('/trace-find/review/access-applications/'.$application->id.'/grant', [
                'permissions' => ['cases.read'],
                'expires_at' => now()->addMonths(1)->toISOString(),
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('trace_country_access_grants', [
            'source_application_id' => $application->id,
        ]);
    }



    public function test_country_access_grant_can_be_revoked_with_a_fresh_otp_and_is_audited(): void
    {
        $reviewer = $this->verifiedUser('revoke-reviewer@example.test');
        $reviewer->forceFill(['phone' => '+254711000103'])->save();
        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);
        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'country',
            'scope_key' => 'KE',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);
        $phone = GroupStaffApprovedPhone::create([
            'user_id' => $reviewer->id,
            'phone_e164' => $reviewer->phone,
            'approved_by' => $reviewer->id,
            'approval_method' => 'staff_approval',
            'approved_at' => now(),
            'status' => 'active',
        ]);
        $applicant = $this->verifiedUser('revoke-applicant@example.test');
        $application = TraceAccessApplication::create([
            'user_id' => $applicant->id,
            'requested_role' => 'professional_investigator',
            'country_code' => 'KE',
            'organisation_name' => 'Independent Investigations',
            'organisation_registration' => 'REG-KE-777',
            'credentials_summary' => 'Verified credentials supplied for access review.',
            'evidence_path' => 'trace-find/access-applications/revoke.pdf',
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => 'Credentials verified.',
        ]);
        $grant = TraceCountryAccessGrant::create([
            'user_id' => $applicant->id,
            'source_application_id' => $application->id,
            'access_role' => 'professional_investigator',
            'country_code' => 'KE',
            'region_code' => 'KE-17',
            'permissions' => ['cases.read'],
            'status' => 'active',
            'granted_by' => $reviewer->id,
            'expires_at' => now()->addMonth(),
        ]);
        $purpose = 'loopza.trace.country_access.revoke.'.$grant->id;
        $challenge = GroupStaffOtpChallenge::create([
            'user_id' => $reviewer->id,
            'approved_phone_id' => $phone->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);
        $session = ['nexora_group_staff_otp' => [
            'challenge_id' => $challenge->id,
            'user_id' => $reviewer->id,
            'purpose' => $purpose,
            'verified_at' => now()->timestamp,
        ]];

        $this->actingAs($reviewer)->withSession($session)
            ->postJson('/trace-find/review/access-applications/grants/'.$grant->id.'/revoke', [
                'reason' => 'Credential status could not be maintained.',
            ])
            ->assertOk()
            ->assertJsonPath('grant.status', 'revoked');

        $this->assertDatabaseHas('trace_country_access_grants', [
            'id' => $grant->id,
            'status' => 'revoked',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $reviewer->id,
            'action' => 'loopza.trace_country_access.revoked',
            'subject_id' => (string) $grant->id,
        ]);
    }

    public function test_reviewer_cannot_decide_their_own_access_application(): void
    {
        $reviewer = $this->verifiedUser('self-reviewer@example.test');
        $reviewer->forceFill(['phone' => '+254711000199'])->save();

        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);
        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'country',
            'scope_key' => 'KE',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);

        $application = TraceAccessApplication::create([
            'user_id' => $reviewer->id,
            'requested_role' => 'professional_investigator',
            'country_code' => 'KE',
            'organisation_name' => 'Self Review Test',
            'organisation_registration' => 'TEST-SELF-001',
            'credentials_summary' => 'Synthetic credentials for a separation of duties security test.',
            'evidence_path' => 'trace-find/access-applications/self-review.pdf',
            'status' => 'pending_review',
        ]);

        $phone = GroupStaffApprovedPhone::create([
            'user_id' => $reviewer->id,
            'phone_e164' => $reviewer->phone,
            'approved_by' => $reviewer->id,
            'approval_method' => 'staff_approval',
            'approved_at' => now(),
            'status' => 'active',
        ]);
        $purpose = 'loopza.trace.access_applications.decision.'.$application->id;
        $challenge = GroupStaffOtpChallenge::create([
            'user_id' => $reviewer->id,
            'approved_phone_id' => $phone->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);
        $session = ['nexora_group_staff_otp' => [
            'challenge_id' => $challenge->id,
            'user_id' => $reviewer->id,
            'purpose' => $purpose,
            'verified_at' => now()->timestamp,
        ]];

        $this->actingAs($reviewer)->withSession($session)
            ->postJson('/trace-find/review/access-applications/'.$application->id.'/decision', [
                'decision' => 'approve',
                'notes' => 'This self-approval must be rejected.',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('trace_access_applications', [
            'id' => $application->id,
            'status' => 'pending_review',
            'reviewed_by' => null,
        ]);
    }



    public function test_reviewer_cannot_see_or_download_their_own_application_evidence(): void
    {
        $reviewer = $this->verifiedUser('self-evidence-reviewer@example.test');
        $reviewer->forceFill(['phone' => '+254711000198'])->save();

        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);
        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'country',
            'scope_key' => 'KE',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);

        $application = TraceAccessApplication::create([
            'user_id' => $reviewer->id,
            'requested_role' => 'professional_investigator',
            'country_code' => 'KE',
            'organisation_name' => 'Self Evidence Test',
            'organisation_registration' => 'TEST-EVIDENCE-001',
            'credentials_summary' => 'Synthetic credentials for a separation of duties security test.',
            'evidence_path' => 'trace-find/access-applications/self-evidence.pdf',
            'status' => 'pending_review',
        ]);

        $phone = GroupStaffApprovedPhone::create([
            'user_id' => $reviewer->id,
            'phone_e164' => $reviewer->phone,
            'approved_by' => $reviewer->id,
            'approval_method' => 'staff_approval',
            'approved_at' => now(),
            'status' => 'active',
        ]);

        $queuePurpose = 'loopza.trace.access_applications.queue';
        $queueChallenge = GroupStaffOtpChallenge::create([
            'user_id' => $reviewer->id,
            'approved_phone_id' => $phone->id,
            'purpose' => $queuePurpose,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);
        $queueSession = ['nexora_group_staff_otp' => [
            'challenge_id' => $queueChallenge->id,
            'user_id' => $reviewer->id,
            'purpose' => $queuePurpose,
            'verified_at' => now()->timestamp,
        ]];

        $this->actingAs($reviewer)->withSession($queueSession)
            ->getJson('/trace-find/review/access-applications')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $evidencePurpose = 'loopza.trace.access_applications.evidence.'.$application->id;
        $evidenceChallenge = GroupStaffOtpChallenge::create([
            'user_id' => $reviewer->id,
            'approved_phone_id' => $phone->id,
            'purpose' => $evidencePurpose,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);
        $evidenceSession = ['nexora_group_staff_otp' => [
            'challenge_id' => $evidenceChallenge->id,
            'user_id' => $reviewer->id,
            'purpose' => $evidencePurpose,
            'verified_at' => now()->timestamp,
        ]];

        $this->actingAs($reviewer)->withSession($evidenceSession)
            ->get('/trace-find/review/access-applications/'.$application->id.'/evidence')
            ->assertForbidden();
    }

}
