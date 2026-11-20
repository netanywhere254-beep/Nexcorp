<?php

namespace Tests\Feature;

use App\Models\TraceAccessApplication;
use App\Models\TraceCase;
use App\Models\TraceCaseDocument;
use App\Models\TraceCountryAccessGrant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoopzaTraceDocumentIdorTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        $user = User::create([
            'name' => 'Document IDOR Test User',
            'email' => $email,
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function publishedCase(User $reporter, string $country = 'KE'): TraceCase
    {
        return TraceCase::create([
            'public_id' => (string) Str::uuid(),
            'case_reference' => 'LTF-'.now()->format('Y').'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'reporter_user_id' => $reporter->id,
            'reporter_phone' => null,
            'reporter_country_code' => 'KE',
            'reporter_region_code' => 'KE-17',
            'incident_country_code' => $country,
            'incident_region_code' => $country === 'KE' ? 'KE-17' : 'UG-01',
            'incident_locality' => 'Synthetic test locality',
            'case_type' => 'missing_person',
            'subject_name' => 'Synthetic subject',
            'subject_description' => 'Synthetic document authorisation test.',
            'last_seen_details' => 'Synthetic test details.',
            'status' => 'published',
            'publication_status' => 'published',
            'urgent' => false,
        ]);
    }

    private function grantDocumentRead(User $investigator, User $reviewer): void
    {
        $application = TraceAccessApplication::create([
            'user_id' => $investigator->id,
            'requested_role' => 'professional_investigator',
            'country_code' => 'KE',
            'organisation_name' => 'Synthetic Investigation Group',
            'organisation_registration' => 'IDOR-TEST-001',
            'credentials_summary' => 'Synthetic credentials for document IDOR regression coverage.',
            'evidence_path' => 'test/evidence.pdf',
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => 'Synthetic test approval.',
        ]);

        TraceCountryAccessGrant::create([
            'user_id' => $investigator->id,
            'source_application_id' => $application->id,
            'access_role' => 'professional_investigator',
            'country_code' => 'KE',
            'region_code' => null,
            'permissions' => ['cases.read', 'documents.read'],
            'status' => 'active',
            'granted_by' => $reviewer->id,
            'expires_at' => now()->addMonth(),
        ]);
    }

    public function test_document_id_cannot_be_replayed_under_a_different_authorised_case_id(): void
    {
        Storage::fake('local');

        $investigator = $this->user('idor-doc-investigator@example.test');
        $reviewer = $this->user('idor-doc-reviewer@example.test');
        $reporter = $this->user('idor-doc-reporter@example.test');
        $this->grantDocumentRead($investigator, $reviewer);

        $caseA = $this->publishedCase($reporter, 'KE');
        $caseB = $this->publishedCase($reporter, 'KE');
        $path = 'trace-find/cases/'.$caseB->public_id.'/restricted/evidence.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 synthetic evidence');

        $document = TraceCaseDocument::create([
            'trace_case_id' => $caseB->id,
            'uploaded_by' => $reporter->id,
            'document_type' => 'authority_report',
            'storage_path' => $path,
            'original_filename' => 'evidence.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen('%PDF-1.4 synthetic evidence'),
            'sha256' => hash('sha256', '%PDF-1.4 synthetic evidence'),
            'verification_status' => 'verified',
        ]);

        $this->actingAs($investigator)
            ->get('/trace-find/authorised/cases/'.$caseA->id.'/documents/'.$document->id)
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_logs', [
            'actor_user_id' => $investigator->id,
            'action' => 'loopza.trace_country_access.published_document.read',
            'subject_id' => (string) $document->id,
        ]);
    }

    public function test_country_grant_cannot_be_bypassed_by_requesting_another_countrys_case_document(): void
    {
        Storage::fake('local');

        $investigator = $this->user('idor-country-investigator@example.test');
        $reviewer = $this->user('idor-country-reviewer@example.test');
        $reporter = $this->user('idor-country-reporter@example.test');
        $this->grantDocumentRead($investigator, $reviewer);

        $foreignCase = $this->publishedCase($reporter, 'UG');
        $path = 'trace-find/cases/'.$foreignCase->public_id.'/restricted/foreign.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 synthetic foreign evidence');

        $document = TraceCaseDocument::create([
            'trace_case_id' => $foreignCase->id,
            'uploaded_by' => $reporter->id,
            'document_type' => 'authority_report',
            'storage_path' => $path,
            'original_filename' => 'foreign.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen('%PDF-1.4 synthetic foreign evidence'),
            'sha256' => hash('sha256', '%PDF-1.4 synthetic foreign evidence'),
            'verification_status' => 'verified',
        ]);

        $this->actingAs($investigator)
            ->get('/trace-find/authorised/cases/'.$foreignCase->id.'/documents/'.$document->id)
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_logs', [
            'actor_user_id' => $investigator->id,
            'action' => 'loopza.trace_country_access.published_document.read',
            'subject_id' => (string) $document->id,
        ]);
    }
}
