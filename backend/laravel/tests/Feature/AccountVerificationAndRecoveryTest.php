<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Auth\Notifications\VerifyEmail;
use Tests\TestCase;

class AccountVerificationAndRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_signed_verification_link_marks_account_verified_and_is_audited(): void
    {
        $user = $this->user(['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->getJson($url)->assertOk()->assertJsonPath('message', 'Email address verified successfully.');

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'user.email_verified',
        ]);
    }

    public function test_invalid_or_expired_verification_link_is_rejected(): void
    {
        $user = $this->user(['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->getJson($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_unverified_account_cannot_sign_in(): void
    {
        $user = $this->user(['email_verified_at' => null]);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'Correct-Horse-42!',
        ])->assertForbidden()
            ->assertJsonPath('email_verification_required', true);

        $this->assertGuest();
    }

    public function test_unverified_account_cannot_open_ai_even_with_an_active_organization(): void
    {
        $user = $this->user(['email_verified_at' => null]);
        $organization = Organization::create([
            'name' => 'Verified Gate Test',
            'slug' => 'verified-gate-test',
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
        $user->organizations()->attach($organization->id, [
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user)->withSession(['active_organization_id' => $organization->id])
            ->getJson('/ai')->assertForbidden();
    }

    public function test_authenticated_unverified_user_can_request_another_verification_email(): void
    {
        $user = $this->user(['email_verified_at' => null]);

        $this->actingAs($user)->postJson('/auth/email/verification-notification')
            ->assertAccepted();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_password_reset_request_is_generic_for_existing_and_unknown_addresses(): void
    {
        $user = $this->user();
        $existing = $this->postJson('/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/auth/forgot-password', ['email' => 'unknown@example.test']);

        $existing->assertAccepted();
        $unknown->assertAccepted();
        $this->assertSame($existing->json('message'), $unknown->json('message'));
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_valid_password_reset_token_changes_password_and_records_audit(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->postJson('/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'Another-Strong-Password-987!',
            'password_confirmation' => 'Another-Strong-Password-987!',
        ])->assertOk();

        $this->assertTrue(password_verify('Another-Strong-Password-987!', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'user.password_reset',
        ]);
    }

    public function test_invalid_password_reset_token_does_not_change_password(): void
    {
        $user = $this->user();

        $this->postJson('/auth/reset-password', [
            'email' => $user->email,
            'token' => 'not-a-real-token',
            'password' => 'Another-Strong-Password-987!',
            'password_confirmation' => 'Another-Strong-Password-987!',
        ])->assertUnprocessable();

        $this->assertTrue(password_verify('Correct-Horse-42!', $user->fresh()->password));
    }

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Recovery Test User',
            'email' => 'recovery-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }
}
