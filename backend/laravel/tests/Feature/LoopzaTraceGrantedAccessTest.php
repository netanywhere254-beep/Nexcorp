<?php

namespace Tests\Feature;

use App\Models\TraceAccessApplication;
use App\Models\TraceCase;
use App\Models\TraceCountryAccessGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoopzaTraceGrantedAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        $user = User::create([
            'name' => 'Vetted investigator',
            'email' => $email,
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function traceCase(User $reporter, string $country, ?string $region, string $publication): TraceCase
    {
        return TraceCase::create([
            'public_id' => (string) Str::uuid(),
            'case_reference' => 'LTF-'.now()->format('Y').'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'reporter_user_id' => $reporter->id,
            'reporter_phone' => null,
            'reporter_country_code' => 'KE',
            'reporter_region_code' => 'KE-17',
            'incident_country_code' => $country,
            'incident_region_code' => $region,
            'incident_locality' => 'Test locality',
            'case_type' => 'missing_person',
            'subject_name' => 'Synthetic test subject',
            'subject_age' => 30,
            'subject_description' => 'Synthetic test data only.',
            'last_seen_details' => 'Synthetic test location details.',
            'status' => 'approved_pending_publication',
            'publication_status' => $publication,
            'urgent' => false,
        ]);
    }

    public function test_investigator_sees_only_published_cases_inside_active_country_and_region_grant(): void
    {
        $investigator = $this->user('investigator-scope@example.test');
        $reporter = $this->user('reporter-scope@example.test');

        $application = TraceAccessApplication::create([
            'user_id' => $investigator->id,
            'requested_role' => 'professional_investigator',
            'country_code' => 'KE',
            'organisation_name' => 'Synthetic Investigations Ltd',
            'organisation_registration' => 'TEST-REG-001',
            'credentials_summary' => 'Synthetic credentials used only for automated permission testing.',
            'evidence_path' => 'test/evidence.pdf',
            'status' => 'approved',
            'reviewed_by' => $reporter->id,
            'reviewed_at' => now(),
            'review_notes' => 'Synthetic test approval.',
        ]);

        TraceCountryAccessGrant::create([
            'user_id' => $investigator->id,
            'source_application_id' => $application->id,
            'access_role' => 'professional_investigator',
            'country_code' => 'KE',
            'region_code' => 'KE-17',
            'permissions' => ['cases.read'],
            'status' => 'active',
            'granted_by' => $reporter->id,
            'expires_at' => now()->addMonth(),
        ]);

        $visible = $this->traceCase($reporter, 'KE', 'KE-17', 'published');
        $wrongRegion = $this->traceCase($reporter, 'KE', 'KE-18', 'published');
        $wrongCountry = $this->traceCase($reporter, 'UG', 'UG-01', 'published');
        $private = $this->traceCase($reporter, 'KE', 'KE-17', 'private');

        $this->actingAs($investigator)->getJson('/trace-find/authorised/cases')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $visible->case_reference)
            ->assertJsonMissing(['reference' => $wrongRegion->case_reference])
            ->assertJsonMissing(['reference' => $wrongCountry->case_reference])
            ->assertJsonMissing(['reference' => $private->case_reference]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $investigator->id,
            'action' => 'loopza.trace_country_access.published_cases.read',
        ]);
    }

    public function test_expired_or_unapproved_grants_do_not_expose_published_cases(): void
    {
        $investigator = $this->user('investigator-expired@example.test');
        $reporter = $this->user('reporter-expired@example.test');
        $application = TraceAccessApplication::create([
            'user_id' => $investigator->id,
            'requested_role' => 'professional_investigator',
            'country_code' => 'KE',
            'organisation_name' => 'Synthetic Investigations Ltd',
            'organisation_registration' => 'TEST-REG-002',
            'credentials_summary' => 'Synthetic credentials used only for automated permission testing.',
            'evidence_path' => 'test/evidence.pdf',
            'status' => 'approved',
            'reviewed_by' => $reporter->id,
            'reviewed_at' => now(),
            'review_notes' => 'Synthetic test approval.',
        ]);

        TraceCountryAccessGrant::create([
            'user_id' => $investigator->id,
            'source_application_id' => $application->id,
            'access_role' => 'professional_investigator',
            'country_code' => 'KE',
            'region_code' => null,
            'permissions' => ['cases.read'],
            'status' => 'active',
            'granted_by' => $reporter->id,
            'expires_at' => now()->subMinute(),
        ]);

        $this->traceCase($reporter, 'KE', 'KE-17', 'published');

        $this->actingAs($investigator)->getJson('/trace-find/authorised/cases')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_revoked_grant_stops_exposing_published_cases_immediately(): void
    {
        $investigator = $this->user('investigator-revoked@example.test');
        $reporter = $this->user('reporter-revoked@example.test');
        $application = TraceAccessApplication::create([
            'user_id' => $investigator->id,
            'requested_role' => 'professional_investigator',
            'country_code' => 'KE',
            'organisation_name' => 'Synthetic Investigations Ltd',
            'organisation_registration' => 'TEST-REG-003',
            'credentials_summary' => 'Synthetic credentials used only for automated permission testing.',
            'evidence_path' => 'test/evidence.pdf',
            'status' => 'approved',
            'reviewed_by' => $reporter->id,
            'reviewed_at' => now(),
            'review_notes' => 'Synthetic test approval.',
        ]);

        TraceCountryAccessGrant::create([
            'user_id' => $investigator->id,
            'source_application_id' => $application->id,
            'access_role' => 'professional_investigator',
            'country_code' => 'KE',
            'region_code' => null,
            'permissions' => ['cases.read'],
            'status' => 'revoked',
            'granted_by' => $reporter->id,
            'expires_at' => now()->addMonth(),
            'revoked_at' => now(),
        ]);

        $this->traceCase($reporter, 'KE', 'KE-17', 'published');

        $this->actingAs($investigator)->getJson('/trace-find/authorised/cases')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

}
