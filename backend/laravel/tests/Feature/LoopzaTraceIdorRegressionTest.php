<?php

namespace Tests\Feature;

use App\Models\TraceCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoopzaTraceIdorRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        $user = User::create([
            'name' => 'IDOR Regression User',
            'email' => $email,
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function rejectedCase(User $reporter): TraceCase
    {
        return TraceCase::create([
            'public_id' => (string) Str::uuid(),
            'case_reference' => 'LTF-'.now()->format('Y').'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'reporter_user_id' => $reporter->id,
            'reporter_phone' => null,
            'reporter_country_code' => 'KE',
            'reporter_region_code' => 'KE-17',
            'incident_country_code' => 'KE',
            'incident_region_code' => 'KE-17',
            'incident_locality' => 'Synthetic test locality',
            'case_type' => 'missing_person',
            'subject_name' => 'Synthetic subject',
            'subject_description' => 'Synthetic regression-test data.',
            'last_seen_details' => 'Synthetic last-seen details.',
            'status' => 'rejected',
            'publication_status' => 'private',
            'urgent' => false,
        ]);
    }

    public function test_user_cannot_resubmit_another_reporters_case_by_substituting_its_numeric_id(): void
    {
        $owner = $this->user('idor-case-owner@example.test');
        $attacker = $this->user('idor-case-attacker@example.test');
        $case = $this->rejectedCase($owner);

        $this->actingAs($attacker)
            ->postJson('/trace-find/cases/'.$case->id.'/resubmit', [
                'reason' => 'Attempted access using another reporter case identifier.',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('trace_cases', [
            'id' => $case->id,
            'reporter_user_id' => $owner->id,
            'status' => 'rejected',
            'publication_status' => 'private',
        ]);

        $this->assertDatabaseMissing('trace_case_resubmissions', [
            'trace_case_id' => $case->id,
            'submitted_by' => $attacker->id,
        ]);
    }

    public function test_case_resubmission_rejects_an_unknown_identifier_without_creating_history(): void
    {
        $user = $this->user('idor-unknown-case@example.test');

        $this->actingAs($user)
            ->postJson('/trace-find/cases/999999999/resubmit', [
                'reason' => 'Attempted resubmission of a non-existent case identifier.',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('trace_case_resubmissions', 0);
    }
}
