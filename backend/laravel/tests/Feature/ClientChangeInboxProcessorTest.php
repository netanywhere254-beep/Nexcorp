<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\ClientChangeInboxProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientChangeInboxProcessorTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(): array
    {
        $organization = Organization::create([
            'name' => 'Backup Tenant', 'slug' => 'backup-tenant', 'type' => 'business',
            'status' => 'active', 'country_code' => 'KE', 'timezone' => 'Africa/Nairobi',
        ]);
        $user = User::create([
            'name' => 'Backup Client', 'email' => 'backup-client@example.test',
            'password' => 'password', 'status' => 'active',
        ]);

        return [$organization, $user];
    }

    private function receiveIdentityEvent(Organization $organization, User $user, int $version = 1): int
    {
        return (int) DB::table('client_change_inbox')->insertGetId([
            'event_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'identity-'.$organization->id.'-'.$version,
            'event_hash' => hash('sha256', 'identity-'.$organization->id.'-'.$version),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'product_code' => 'nexisp',
            'aggregate_type' => 'client_identity',
            'aggregate_id' => 'ISP-1001',
            'event_type' => 'client.identity.upserted',
            'aggregate_version' => $version,
            'payload' => json_encode([
                'external_reference' => 'ISP-1001',
                'status' => 'active',
                'metadata' => ['source' => 'primary'],
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_processor_materializes_a_valid_identity_event_once(): void
    {
        [$organization, $user] = $this->createTenant();
        $eventId = $this->receiveIdentityEvent($organization, $user);

        $result = app(ClientChangeInboxProcessor::class)->processPending();

        $this->assertSame(['processed' => 1, 'failed' => 0, 'blocked' => 0], $result);
        $this->assertDatabaseHas('client_service_identities', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'product_code' => 'nexisp',
            'external_reference' => 'ISP-1001',
            'source_version' => 1,
            'status' => 'active',
        ]);
        $this->assertNotNull(DB::table('client_change_inbox')->where('id', $eventId)->value('processed_at'));

        $secondRun = app(ClientChangeInboxProcessor::class)->processPending();
        $this->assertSame(['processed' => 0, 'failed' => 0, 'blocked' => 0], $secondRun);
        $this->assertDatabaseCount('client_service_identities', 1);
    }

    public function test_processor_does_not_apply_an_identity_version_gap(): void
    {
        [$organization, $user] = $this->createTenant();
        $eventId = $this->receiveIdentityEvent($organization, $user, 2);

        $result = app(ClientChangeInboxProcessor::class)->processPending();

        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseCount('client_service_identities', 0);
        $this->assertDatabaseHas('client_change_inbox', [
            'id' => $eventId,
            'processed_at' => null,
            'attempts' => 1,
        ]);
        $this->assertStringContainsString('Identity version gap', (string) DB::table('client_change_inbox')->where('id', $eventId)->value('last_error'));
    }

    public function test_processor_never_acknowledges_case_events_without_a_case_materializer(): void
    {
        $eventId = (int) DB::table('client_change_inbox')->insertGetId([
            'event_uuid' => (string) Str::uuid(),
            'idempotency_key' => 'loopza-case-registration-unsupported',
            'event_hash' => hash('sha256', 'loopza-case-registration-unsupported'),
            'organization_id' => null,
            'user_id' => 9001,
            'product_code' => 'loopza',
            'aggregate_type' => 'trace_case',
            'aggregate_id' => '501',
            'event_type' => 'trace_case.registered',
            'aggregate_version' => 1,
            'payload' => json_encode([
                'case_reference' => 'LOOP-2026-000501',
                'status' => 'pending_review',
                'publication_status' => 'private',
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(ClientChangeInboxProcessor::class)->processPending();

        $this->assertSame(0, $result['processed']);
        $this->assertSame(1, $result['failed']);
        $this->assertDatabaseHas('client_change_inbox', [
            'id' => $eventId,
            'processed_at' => null,
            'attempts' => 1,
        ]);
        $this->assertStringContainsString(
            'No inbox handler is registered',
            (string) DB::table('client_change_inbox')->where('id', $eventId)->value('last_error')
        );
        $this->assertDatabaseCount('trace_cases', 0);
    }

}
