<?php

namespace Tests\Feature;

use App\Models\NexoraCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NexoraCaseRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function verifiedUser(bool $phoneVerified = true): User
    {
        $user = User::create([
            'name' => 'Nexora Reporter',
            'email' => 'nexora-reporter@example.test',
            'phone' => '+254711000001',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        if ($phoneVerified) {
            DB::table('user_phone_verifications')->insert([
                'user_id' => $user->id,
                'phone_e164' => $user->phone,
                'verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    public function test_case_registration_requires_verified_account_phone_otp(): void
    {
        $user = $this->verifiedUser(false);

        $this->actingAs($user)->postJson('/nexora/cases', [
            'case_type' => 'complaint',
            'title' => 'A service complaint',
            'description' => 'The service is not operating as expected.',
        ])->assertForbidden()
            ->assertJsonPath('verification_required', 'account_phone_otp');

        $this->assertDatabaseCount('nexora_cases', 0);
    }

    public function test_verified_user_receives_a_private_nexora_reference(): void
    {
        $user = $this->verifiedUser();

        $response = $this->actingAs($user)->postJson('/nexora/cases', [
            'case_type' => 'security_incident',
            'title' => 'Suspicious account activity',
            'description' => 'I noticed an unfamiliar login and need the security team to review it.',
            'priority' => 'high',
        ]);

        $response->assertCreated()
            ->assertJsonPath('case.reference', 'NEX-'.now()->format('Y').'-000001')
            ->assertJsonPath('case.status', 'submitted')
            ->assertJsonPath('case.visibility', 'private');

        $case = NexoraCase::query()->firstOrFail();
        $this->assertDatabaseHas('client_change_outbox', [
            'product_code' => 'nexora',
            'aggregate_type' => 'case',
            'aggregate_id' => (string) $case->id,
            'event_type' => 'case.registered',
            'user_id' => $user->id,
        ]);
        $this->assertSame('Suspicious account activity', $case->title);
        $this->assertSame(
            'I noticed an unfamiliar login and need the security team to review it.',
            $case->description
        );

        $rawTitle = DB::table('nexora_cases')->where('id', $case->id)->value('title');
        $this->assertNotSame('Suspicious account activity', $rawTitle);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'action' => 'nexora.case.registered',
            'subject_id' => (string) $case->id,
        ]);
    }

    public function test_users_can_only_list_their_own_cases(): void
    {
        $owner = $this->verifiedUser();
        $other = User::create([
            'name' => 'Other Reporter',
            'email' => 'other-reporter@example.test',
            'phone' => '+254711000002',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);

        NexoraCase::create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'case_reference' => 'NEX-'.now()->format('Y').'-000099',
            'reporter_user_id' => $other->id,
            'case_type' => 'other',
            'title' => 'Private case belonging to another user',
            'description' => 'This record must never appear in another user account.',
            'priority' => 'normal',
            'status' => 'submitted',
            'visibility' => 'private',
        ]);

        $this->actingAs($owner)->getJson('/nexora/cases')
            ->assertOk()
            ->assertJsonCount(0, 'cases');
    }
}
