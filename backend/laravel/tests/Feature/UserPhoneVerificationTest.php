<?php

namespace Tests\\Feature;

use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Support\\Facades\\Http;
use Tests\\TestCase;

class UserPhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(string $email): User
    {
        $user = User::create([
            'name' => 'Account User',
            'email' => $email,
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    public function test_public_user_can_verify_their_own_phone_with_separate_account_otp(): void
    {
        $user = $this->verifiedUser('account-phone@example.test');
        config(['services.nexora_public_sms.url' => 'https://sms.example.test/send']);
        $message = '';
        Http::fake(function ($request) use (&$message) {
            $message = $request['message'];
            return Http::response(['accepted' => true], 200);
        });

        $this->actingAs($user)->postJson('/account/phone/send-code', [
            'phone' => '+254712345678',
        ])->assertOk();

        preg_match('/([0-9]{6})/', $message, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $this->actingAs($user)->postJson('/account/phone/verify-code', [
            'phone' => '+254712345678',
            'code' => $matches[1],
        ])->assertOk()->assertJsonPath('verified', true);

        $this->assertSame('+254712345678', $user->fresh()->phone);
        $this->assertDatabaseHas('user_phone_verifications', [
            'user_id' => $user->id,
            'phone_e164' => '+254712345678',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'account.phone_verified',
        ]);
    }

    public function test_account_phone_verification_does_not_grant_trace_find_phone_verification(): void
    {
        $user = $this->verifiedUser('account-trace-separation@example.test');
        $user->traceProfile()->create([
            'country_code' => 'KE',
            'region_code' => 'KE-17',
            'account_type' => 'family',
            'verification_status' => 'pending',
            'phone_verification_status' => 'pending',
            'location_verification_status' => 'pending',
            'selfie_verification_status' => 'pending',
        ]);
        config(['services.nexora_public_sms.url' => 'https://sms.example.test/send']);
        $message = '';
        Http::fake(function ($request) use (&$message) {
            $message = $request['message'];
            return Http::response(['accepted' => true], 200);
        });

        $this->actingAs($user)->postJson('/account/phone/send-code', ['phone' => '+254712345679'])->assertOk();
        preg_match('/([0-9]{6})/', $message, $matches);
        $this->actingAs($user)->postJson('/account/phone/verify-code', [
            'phone' => '+254712345679', 'code' => $matches[1],
        ])->assertOk();

        $this->assertSame('pending', $user->fresh()->traceProfile->phone_verification_status);
    }

    public function test_account_phone_otp_cannot_be_used_by_another_user(): void
    {
        $owner = $this->verifiedUser('phone-owner@example.test');
        $other = $this->verifiedUser('phone-other@example.test');
        config(['services.nexora_public_sms.url' => 'https://sms.example.test/send']);
        $message = '';
        Http::fake(function ($request) use (&$message) {
            $message = $request['message'];
            return Http::response(['accepted' => true], 200);
        });

        $this->actingAs($owner)->postJson('/account/phone/send-code', ['phone' => '+254712345680'])->assertOk();
        preg_match('/([0-9]{6})/', $message, $matches);

        $this->actingAs($other)->postJson('/account/phone/verify-code', [
            'phone' => '+254712345680', 'code' => $matches[1],
        ])->assertUnprocessable();
        $this->assertDatabaseMissing('user_phone_verifications', ['phone_e164' => '+254712345680']);
    }

    public function test_public_phone_verification_refuses_a_number_already_verified_by_another_account(): void
    {
        $owner = $this->verifiedUser('verified-owner@example.test');
        $other = $this->verifiedUser('verified-other@example.test');
        \\DB::table('user_phone_verifications')->insert([
            'user_id' => $owner->id,
            'phone_e164' => '+254712345681',
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config(['services.nexora_public_sms.url' => 'https://sms.example.test/send']);
        Http::fake();

        $this->actingAs($other)->postJson('/account/phone/send-code', [
            'phone' => '+254712345681',
        ])->assertUnprocessable();
    }

    public function test_account_phone_verification_requires_an_https_sms_provider(): void
    {
        $user = $this->verifiedUser('phone-no-provider@example.test');
        config(['services.nexora_public_sms.url' => 'http://sms.example.test/send']);

        $this->actingAs($user)->postJson('/account/phone/send-code', [
            'phone' => '+254712345682',
        ])->assertStatus(503);
    }
}
