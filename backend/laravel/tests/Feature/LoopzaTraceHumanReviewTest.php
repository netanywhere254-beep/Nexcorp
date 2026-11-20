<?php

namespace Tests\Feature;

use App\Models\GroupAuthority;
use App\Models\GroupStaffApprovedPhone;
use App\Models\GroupStaffOtpChallenge;
use App\Models\TraceCase;
use App\Models\TraceCaseDocument;
use App\Models\TraceProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LoopzaTraceHumanReviewTest extends TestCase
{
    use RefreshDatabase;

    private function reviewerWithOtp(string $purpose): array
    {
        $user = User::create([
            'name' => 'Group Security Reviewer',
            'email' => 'security-reviewer-'.\Illuminate\Support\Str::uuid().'@example.test',
            'phone' => '+2547'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        GroupAuthority::create([
            'user_id' => $user->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);
        GroupAuthority::create([
            'user_id' => $user->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'country',
            'scope_key' => 'KE',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);

        $phone = GroupStaffApprovedPhone::create([
            'user_id' => $user->id,
            'phone_e164' => $user->phone,
            'approved_by' => $user->id,
            'approval_method' => 'staff_approval',
            'approved_at' => now(),
            'status' => 'active',
        ]);

        $challenge = GroupStaffOtpChallenge::create([
            'user_id' => $user->id,
            'approved_phone_id' => $phone->id,
            'purpose' => $purpose,
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);

        return [
            $user,
            [
                'nexora_group_staff_otp' => [
                    'challenge_id' => $challenge->id,
                    'user_id' => $user->id,
                    'purpose' => $purpose,
                    'verified_at' => now()->timestamp,
                ],
            ],
        ];
    }

    public function test_non_reviewer_cannot_open_trace_review_queue(): void
    {
        $user = User::create([
            'name' => 'Ordinary User',
            'email' => 'ordinary@example.test',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($user)->getJson('/trace-find/review/profiles')
            ->assertForbidden();
    }

    public function test_profile_verification_requires_group_appointment_and_fresh_otp(): void
    {
        [$reviewer, $session] = $this->reviewerWithOtp('loopza.trace.review.profile.1');

        $reporter = User::create([
            'name' => 'Family Reporter',
            'email' => 'family@example.test',
            'phone' => '+254711000092',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);

        $profile = $reporter->traceProfile()->create([
            'country_code' => 'KE',
            'region_code' => 'KE-17',
            'locality' => 'Kisii',
            'account_type' => 'family',
            'verification_status' => 'pending',
            'identity_verification_status' => 'pending',
            'phone_verification_status' => 'verified',
            'location_verification_status' => 'pending',
            'selfie_verification_status' => 'pending',
            'selfie_path' => 'trace-find/identity/pending/sample.png',
            'location_consent_at' => now(),
            'biometric_processing_consent_at' => now(),
            'selfie_submitted_at' => now(),
            'submitted_latitude' => '-0.6817000',
            'submitted_longitude' => '34.7667000',
        ]);

        $session['nexora_group_staff_otp']['purpose'] = 'loopza.trace.review.profile.'.$profile->id;

        $response = $this->actingAs($reviewer)->withSession($session)
            ->postJson('/trace-find/review/profiles/'.$profile->id.'/decision', [
                'identity_decision' => 'approved',
                'location_decision' => 'approved',
                'selfie_decision' => 'approved',
                'notes' => 'Evidence reviewed by the authorised group security reviewer.',
            ]);

        $response->assertOk()
            ->assertJsonPath('profile.verification_status', 'verified');

        $this->assertDatabaseHas('trace_profiles', [
            'id' => $profile->id,
            'identity_verification_status' => 'verified',
            'location_verification_status' => 'verified',
            'selfie_verification_status' => 'verified',
            'verification_status' => 'verified',
            'reviewed_by' => $reviewer->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $reviewer->id,
            'action' => 'loopza.trace_profile.reviewed',
            'subject_id' => (string) $profile->id,
        ]);
    }

    public function test_case_approval_does_not_publish_case(): void
    {
        [$reviewer, $session] = $this->reviewerWithOtp('loopza.trace.review.case.1');

        $reporter = User::create([
            'name' => 'Case Reporter',
            'email' => 'case-reporter@example.test',
            'phone' => '+254711000093',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);

        $case = TraceCase::create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'case_reference' => 'LTF-'.now()->format('Y').'-000123',
            'reporter_user_id' => $reporter->id,
            'reporter_phone' => $reporter->phone,
            'reporter_country_code' => 'KE',
            'reporter_region_code' => 'KE-17',
            'incident_country_code' => 'KE',
            'incident_region_code' => 'KE-17',
            'incident_locality' => 'Kisii',
            'case_type' => 'missing_person',
            'subject_name' => 'Private Test Subject',
            'subject_description' => 'Private test description.',
            'last_seen_details' => 'Last seen in the test locality.',
            'status' => 'pending_review',
            'publication_status' => 'private',
            'urgent' => false,
        ]);

        [$reviewer, $session] = $this->reviewerWithOtp('loopza.trace.review.case.'.$case->id);

        $this->actingAs($reviewer)->withSession($session)
            ->postJson('/trace-find/review/cases/'.$case->id.'/decision', [
                'decision' => 'approve',
                'notes' => 'Initial review approved; publication remains a separate decision.',
            ])
            ->assertOk()
            ->assertJsonPath('case.status', 'approved_pending_publication')
            ->assertJsonPath('case.publication_status', 'private');

        $this->assertDatabaseHas('trace_cases', [
            'id' => $case->id,
            'status' => 'approved_pending_publication',
            'publication_status' => 'private',
            'reviewed_by' => $reviewer->id,
        ]);
    }

    public function test_sensitive_profile_queue_read_is_audited_and_otp_is_consumed(): void
    {
        [$reviewer, $session] = $this->reviewerWithOtp('loopza.trace.review.profiles.queue');

        $this->actingAs($reviewer)->withSession($session)
            ->getJson('/trace-find/review/profiles')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $reviewer->id,
            'action' => 'loopza.trace_profile.review_queue.accessed',
            'subject_type' => TraceProfile::class,
        ]);

        $this->actingAs($reviewer)->withSession($session)
            ->getJson('/trace-find/review/profiles')
            ->assertForbidden();
    }


    public function test_private_case_document_download_requires_reviewer_otp_and_is_audited(): void
    {
        [$reviewer, $session] = $this->reviewerWithOtp('loopza.trace.review.case.document.1');
        $reporter = User::create([
            'name' => 'Document Reporter',
            'email' => 'document-reporter@example.test',
            'phone' => '+254711000094',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);

        $case = TraceCase::create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'case_reference' => 'LTF-'.now()->format('Y').'-000124',
            'reporter_user_id' => $reporter->id,
            'reporter_phone' => $reporter->phone,
            'reporter_country_code' => 'KE',
            'reporter_region_code' => 'KE-17',
            'incident_country_code' => 'KE',
            'incident_region_code' => 'KE-17',
            'incident_locality' => 'Kisii',
            'case_type' => 'missing_person',
            'subject_name' => 'Private Document Subject',
            'subject_description' => 'Private test description.',
            'last_seen_details' => 'Last seen in the test locality.',
            'status' => 'pending_review',
            'publication_status' => 'private',
            'urgent' => false,
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('trace-find/cases/restricted/report.pdf', '%PDF-1.4 test');
        $document = TraceCaseDocument::create([
            'trace_case_id' => $case->id,
            'uploaded_by' => $reporter->id,
            'document_type' => 'authority_report',
            'storage_path' => 'trace-find/cases/restricted/report.pdf',
            'original_filename' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 13,
            'sha256' => hash('sha256', '%PDF-1.4 test'),
            'verification_status' => 'pending',
        ]);

        $session['nexora_group_staff_otp']['purpose'] = 'loopza.trace.review.case.document.'.$document->id;

        $response = $this->actingAs($reviewer)->withSession($session)
            ->get('/trace-find/review/cases/'.$case->id.'/documents/'.$document->id);

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $reviewer->id,
            'action' => 'loopza.trace_case.document.accessed',
            'subject_id' => (string) $document->id,
        ]);
    }

    public function test_reviewer_cannot_decide_a_case_outside_their_country_appointment(): void
    {
        $reporter = User::create([
            'name' => 'Uganda Case Reporter',
            'email' => 'uganda-case-reporter@example.test',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);

        $case = TraceCase::create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'case_reference' => 'LTF-'.now()->format('Y').'-000999',
            'reporter_user_id' => $reporter->id,
            'reporter_phone' => null,
            'reporter_country_code' => 'UG',
            'reporter_region_code' => 'UG-01',
            'incident_country_code' => 'UG',
            'incident_region_code' => 'UG-01',
            'incident_locality' => 'Kampala',
            'case_type' => 'missing_person',
            'subject_name' => 'Private cross-border test subject',
            'subject_description' => 'Synthetic test data only.',
            'last_seen_details' => 'Synthetic test location details.',
            'status' => 'pending_review',
            'publication_status' => 'private',
            'urgent' => false,
        ]);

        [$reviewer, $session] = $this->reviewerWithOtp('loopza.trace.review.case.'.$case->id);

        $this->actingAs($reviewer)->withSession($session)
            ->postJson('/trace-find/review/cases/'.$case->id.'/decision', [
                'decision' => 'approve',
                'notes' => 'This cross-border decision must be denied.',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('trace_cases', [
            'id' => $case->id,
            'status' => 'pending_review',
            'publication_status' => 'private',
        ]);
    }

    public function test_group_reviewer_without_country_appointment_cannot_open_trace_queue(): void
    {
        $reviewer = User::create([
            'name' => 'Unscoped Group Reviewer',
            'email' => 'unscoped-reviewer@example.test',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);
        $reviewer->forceFill(['email_verified_at' => now()])->save();

        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'group',
            'scope_key' => 'nexora',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
        ]);

        $this->actingAs($reviewer)->getJson('/trace-find/review/profiles')
            ->assertForbidden();
    }

}
