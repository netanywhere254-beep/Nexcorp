<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LoopzaTraceFindFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        return User::create([
            'name' => 'Trace Reporter',
            'email' => 'reporter@example.test',
            'phone' => '+254700000001',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    public function test_public_onboarding_only_creates_a_pending_family_profile(): void
    {
        $user = $this->verifiedUser();

        $response = $this->actingAs($user)->withHeader('Accept', 'application/json')->post('/trace-find/profile', [
            'country_code' => 'KE',
            'region_code' => 'KE-17',
            'locality' => 'Kisii',
            'latitude' => '-0.6817',
            'longitude' => '34.7667',
            'selfie' => UploadedFile::fake()->createWithContent('selfie.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC')),
            'location_consent' => '1',
            'biometric_processing_consent' => '1',
            'account_type' => 'authority',
            'verification_status' => 'verified',
        ]);

        $response->assertCreated()->assertJsonPath('profile.verification_status', 'pending');
        $this->assertDatabaseHas('trace_profiles', [
            'user_id' => $user->id,
            'account_type' => 'family',
            'verification_status' => 'pending',
            'location_verification_status' => 'pending',
            'selfie_verification_status' => 'pending',
        ]);
    }

    public function test_cross_region_case_is_accepted_for_review_but_not_published(): void
    {
        $user = $this->verifiedUser();
        $user->traceProfile()->create([
            'country_code' => 'KE',
            'region_code' => 'KE-17',
            'account_type' => 'family',
            'verification_status' => 'verified',
            'phone_verification_status' => 'verified',
            'location_verification_status' => 'verified',
            'selfie_verification_status' => 'verified',
        ]);

        $response = $this->actingAs($user)->postJson('/trace-find/cases', [
            'incident_country_code' => 'KE',
            'incident_region_code' => 'KE-42',
            'subject_name' => 'Test Missing Person',
            'subject_description' => 'Test description for a case submission.',
            'last_seen_details' => 'Last seen near the public market.',
            'urgent' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('case.status', 'cross_region_review')
            ->assertJsonPath('case.publication_status', 'private');

        $this->assertDatabaseHas('trace_cases', [
            'reporter_user_id' => $user->id,
            'incident_region_code' => 'KE-42',
            'status' => 'cross_region_review',
            'publication_status' => 'private',
        ]);
    }

    public function test_unverified_location_and_selfie_do_not_receive_local_posting_status(): void
    {
        $user = $this->verifiedUser();
        $user->traceProfile()->create([
            'country_code' => 'KE',
            'region_code' => 'KE-17',
            'account_type' => 'family',
            'verification_status' => 'pending',
            'phone_verification_status' => 'pending',
            'location_verification_status' => 'pending',
            'selfie_verification_status' => 'pending',
        ]);

        $response = $this->actingAs($user)->postJson('/trace-find/cases', [
            'incident_country_code' => 'KE',
            'incident_region_code' => 'KE-17',
            'subject_name' => 'Pending Verification Case',
            'subject_description' => 'Test description.',
            'last_seen_details' => 'Last seen in the locality.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('case.status', 'cross_region_review')
            ->assertJsonPath('case.publication_status', 'private');
    }
}
