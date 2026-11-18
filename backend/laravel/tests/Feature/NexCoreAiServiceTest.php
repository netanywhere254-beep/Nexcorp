<?php

namespace Tests\Feature;

use App\Models\AiFeatureEntitlement;
use App\Models\AiOrganizationBudget;
use App\Models\AiUsageLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\NexCoreAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class NexCoreAiServiceTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $organization = Organization::create([
            'name' => 'Nexora Test Tenant',
            'slug' => 'nexora-test-tenant',
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
        $user = User::create([
            'name' => 'Test User',
            'email' => 'ai-test@example.test',
            'password' => 'A-Strong-Test-Password-123!',
            'status' => 'active',
        ]);

        foreach ([
            ['product_key' => 'nexora', 'feature_key' => 'general'],
            ['product_key' => 'nexisp', 'feature_key' => 'nexisp.support'],
        ] as $entitlement) {
            AiFeatureEntitlement::create([
                'organization_id' => $organization->id,
                ...$entitlement,
                'is_enabled' => true,
                'granted_by_user_id' => $user->id,
                'enabled_at' => now(),
            ]);
        }

        return [$organization, $user];
    }

    public function test_it_calls_configured_provider_and_logs_usage_without_storing_prompt(): void
    {
        [$organization, $user] = $this->context();
        AiOrganizationBudget::create([
            'organization_id' => $organization->id,
            'monthly_request_limit' => 10,
            'requests_used' => 0,
            'max_output_tokens' => 100,
            'period_start' => now()->startOfMonth()->toDateString(),
            'is_enabled' => true,
        ]);
        config([
            'nexcore_ai.enabled' => true,
            'nexcore_ai.api_key' => 'test-secret',
            'nexcore_ai.base_url' => 'https://ai.example.test/v1',
            'nexcore_ai.model' => 'test-model',
        ]);
        Http::fake([
            'ai.example.test/*' => Http::response([
                'choices' => [['message' => ['content' => 'Check the router power and WAN link.']]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 9, 'total_tokens' => 21],
            ]),
        ]);

        $result = app(NexCoreAiService::class)->assist($organization, $user, 'nexisp.support', 'My connection is down');

        $this->assertSame('Check the router power and WAN link.', $result['answer']);
        $this->assertDatabaseHas('ai_usage_logs', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'product_key' => 'nexisp',
            'feature' => 'nexisp.support',
            'status' => 'succeeded',
            'total_tokens' => 21,
        ]);
        $log = AiUsageLog::firstOrFail();
        $this->assertSame(['content_stored' => false], $log->metadata);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-secret'));
    }

    public function test_it_fails_closed_when_provider_is_not_configured(): void
    {
        [$organization, $user] = $this->context();
        config(['nexcore_ai.enabled' => false, 'nexcore_ai.api_key' => null]);

        try {
            app(NexCoreAiService::class)->assist($organization, $user, 'general', 'Help me plan a task');
            $this->fail('Expected unavailable provider exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('NexCore AI is not configured.', $exception->getMessage());
        }

        $this->assertDatabaseHas('ai_usage_logs', ['status' => 'unavailable']);
    }

    public function test_it_rejects_unknown_features_before_sending_a_request(): void
    {
        [$organization, $user] = $this->context();
        config(['nexcore_ai.enabled' => true, 'nexcore_ai.api_key' => 'test-secret']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported AI feature.');

        app(NexCoreAiService::class)->assist($organization, $user, 'finance.transfer', 'Move funds');
    }

    public function test_it_blocks_requests_when_the_tenant_monthly_budget_is_exhausted(): void
    {
        [$organization, $user] = $this->context();
        AiOrganizationBudget::create([
            'organization_id' => $organization->id,
            'monthly_request_limit' => 1,
            'requests_used' => 1,
            'max_output_tokens' => 100,
            'period_start' => now()->startOfMonth()->toDateString(),
            'is_enabled' => true,
        ]);
        config(['nexcore_ai.enabled' => true, 'nexcore_ai.api_key' => 'test-secret']);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NexCore AI monthly request budget reached.');

        app(NexCoreAiService::class)->assist($organization, $user, 'general', 'Help me plan a task');
    }

    public function test_it_fails_closed_when_no_tenant_budget_is_enabled(): void
    {
        [$organization, $user] = $this->context();
        config(['nexcore_ai.enabled' => true, 'nexcore_ai.api_key' => 'test-secret']);
        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NexCore AI budget is not enabled for this organization.');

        app(NexCoreAiService::class)->assist($organization, $user, 'general', 'Help me plan a task');
    }

}
