<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoopzaTraceFindFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(): User
    {
        $user = User::create([
            'name' => 'Trace Reporter',
            'email' => 'reporter@example.test',
            'phone' => '+254700000001',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
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

        $response->assertForbidden()
            ->assertJsonPath('message', 'Verify your contact phone number by OTP before registering a case.');
    }

    public function test_phone_otp_is_sent_and_verified_before_case_registration(): void
    {
        $user = $this->verifiedUser();
        $user->traceProfile()->create([
            'country_code' => 'KE', 'region_code' => 'KE-17', 'account_type' => 'family',
            'verification_status' => 'pending', 'phone_verification_status' => 'verified',
            'location_verification_status' => 'pending', 'selfie_verification_status' => 'pending',
        ]);

        config(['services.trace_find_sms.url' => 'https://sms.example.test/send']);
        $sentMessage = '';
        Http::fake(function ($request) use (&$sentMessage) {
            $sentMessage = $request['message'];
            return Http::response(['accepted' => true], 200);
        });

        $this->actingAs($user)->postJson('/trace-find/phone/send-code', ['phone' => '+254700000009'])
            ->assertOk();
        $this->assertDatabaseHas('trace_phone_verifications', [
            'user_id' => $user->id, 'phone' => '+254700000009', 'attempts' => 0,
        ]);
        $this->assertSame('pending', $user->fresh()->traceProfile->phone_verification_status);
        $this->assertMatchesRegularExpression('/[0-9]{6}/', $sentMessage);
        preg_match('/([0-9]{6})/', $sentMessage, $matches);

        $this->actingAs($user)->postJson('/trace-find/phone/verify-code', [
            'phone' => '+254700000009', 'code' => $matches[1],
        ])->assertOk();

        $this->assertSame('+254700000009', $user->fresh()->phone);
        $this->assertSame('verified', $user->fresh()->traceProfile->phone_verification_status);
    }
}
