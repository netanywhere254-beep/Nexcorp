<?php

namespace Tests\Feature;

use App\Models\GroupAuthority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
}