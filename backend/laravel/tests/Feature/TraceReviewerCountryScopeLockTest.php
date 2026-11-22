<?php

namespace Tests\Feature;

use App\Models\GroupAuthority;
use App\Models\User;
use App\Services\TraceReviewerCountryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TraceReviewerCountryScopeLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_scope_reads_and_locked_reads_include_only_current_valid_appointments(): void
    {
        $reviewer = User::create([
            'name' => 'Scope Reviewer',
            'email' => 'scope-reviewer@example.test',
            'password' => 'Strong-test-password-123',
            'status' => 'active',
        ]);

        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'country',
            'scope_key' => 'ke',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
        ]);

        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'country',
            'scope_key' => 'UG',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->subMinute(),
        ]);

        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'country',
            'scope_key' => 'TZ',
            'status' => 'revoked',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
        ]);

        GroupAuthority::create([
            'user_id' => $reviewer->id,
            'authority' => 'GROUP_SECURITY_OFFICER',
            'scope_type' => 'country',
            'scope_key' => 'INVALID',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
        ]);

        $scope = app(TraceReviewerCountryScope::class);

        $this->assertSame(['KE'], $scope->countriesFor($reviewer));

        $lockedCountries = \Illuminate\Support\Facades\DB::transaction(
            fn () => $scope->lockCountriesFor($reviewer)
        );

        $this->assertSame(['KE'], $lockedCountries);
    }
}
