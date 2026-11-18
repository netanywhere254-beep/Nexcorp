<?php

namespace Tests\Feature;

use Tests\TestCase;

class NexCoreAiWorkspaceTest extends TestCase
{
    public function test_ai_workspace_requires_an_authenticated_session(): void
    {
        $this->withHeader('Accept', 'application/json')
            ->get('/ai')
            ->assertUnauthorized();
    }
}
