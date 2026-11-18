<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\NexCoreAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class NexCoreAiEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_feature_is_denied_when_the_organization_has_no_explicit_entitlement(): void
    {
        $organization = Organization::create([
            'name' => 'Test tenant',
            'slug' => 'test-tenant',
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.test',
            'password' => 'A-long-test-password-123!',
            'status' => 'active',
        ]);

        try {
            app(NexCoreAiService::class)->assist(
                $organization,
                $user,
                'nexisp.support',
                'Help me diagnose a slow connection.'
            );

            $this->fail('An organization without an AI entitlement must be denied.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'NexCore AI feature is not enabled for this organization.',
                $exception->getMessage()
            );
        }
    }
}
