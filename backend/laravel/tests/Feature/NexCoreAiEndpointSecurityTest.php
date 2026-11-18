<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NexCoreAiEndpointSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_endpoint_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/ai/assist', [
            'feature' => 'general',
            'message' => 'Help me plan a task.',
        ])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_ai_endpoint_requires_server_verified_organization_context(): void
    {
        $user = User::create([
            'name' => 'AI Endpoint Test',
            'email' => 'ai-endpoint@example.test',
            'password' => 'A-Strong-Test-Password-123!',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->postJson('/ai/assist', [
                'feature' => 'general',
                'message' => 'Help me plan a task.',
                // A client-supplied tenant ID must not replace the session context.
                'organization_id' => 999999,
            ])
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
