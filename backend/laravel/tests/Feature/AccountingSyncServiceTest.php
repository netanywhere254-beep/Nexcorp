<?php

namespace Tests\Feature;

use App\Models\AccountingAccountMapping;
use App\Models\AccountingConnection;
use App\Models\AccountingSyncItem;
use App\Models\NexispCustomer;
use App\Models\NexispInvoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nexisp.phone_hash_key' => 'test-only-stable-phone-hash-secret']);
    }

    public function test_billing_user_can_queue_invoice_export_for_connected_accounting_provider(): void
    {
        [$actor, $organization, $connection, $invoice] = $this->fixture();

        $item = app(AccountingSyncService::class)->queueInvoice(
            $actor,
            $organization,
            $connection->id,
            $invoice->id,
        );

        $this->assertSame('queued', $item->status);
        $this->assertSame($organization->id, $item->organization_id);
        $this->assertSame($connection->id, $item->accounting_connection_id);
        $this->assertSame($invoice->id, $item->local_id);
        $this->assertDatabaseHas('accounting_sync_runs', [
            'id' => $item->accounting_sync_run_id,
            'organization_id' => $organization->id,
            'status' => 'queued',
            'items_total' => 1,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $actor->id,
            'action' => 'nexisp.accounting.invoice_export_queued',
            'subject_id' => (string) $item->id,
        ]);
    }

    public function test_repeated_invoice_export_requests_are_idempotent(): void
    {
        [$actor, $organization, $connection, $invoice] = $this->fixture();
        $service = app(AccountingSyncService::class);

        $first = $service->queueInvoice($actor, $organization, $connection->id, $invoice->id);
        $second = $service->queueInvoice($actor, $organization, $connection->id, $invoice->id);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('accounting_sync_items', 1);
        $this->assertDatabaseCount('accounting_sync_runs', 1);
    }

    public function test_accounting_export_cannot_use_another_organizations_connection_or_invoice(): void
    {
        [$actor, $organization] = $this->makeBillingActor();
        [, , $foreignConnection, $foreignInvoice] = $this->fixture();

        try {
            app(AccountingSyncService::class)->queueInvoice(
                $actor,
                $organization,
                $foreignConnection->id,
                $foreignInvoice->id,
            );
            $this->fail('Cross-tenant accounting exports must not be possible.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertDatabaseCount('accounting_sync_items', 0);
        }
    }

    public function test_invoice_export_requires_account_mappings_and_connected_status(): void
    {
        [$actor, $organization, $connection, $invoice] = $this->fixture(false);

        try {
            app(AccountingSyncService::class)->queueInvoice($actor, $organization, $connection->id, $invoice->id);
            $this->fail('Exports without chart-of-account mappings must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mappings', $exception->errors());
        }

        $connection->update(['status' => 'revoked']);

        try {
            app(AccountingSyncService::class)->queueInvoice($actor, $organization, $connection->id, $invoice->id);
            $this->fail('Revoked accounting connections must not accept exports.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('connection', $exception->errors());
        }
    }

    public function test_accounting_oauth_tokens_are_encrypted_and_hidden_from_serialization(): void
    {
        [$actor, $organization, $connection] = $this->makeBillingActor();
        $connection = AccountingConnection::create([
            'organization_id' => $organization->id,
            'connected_by_user_id' => $actor->id,
            'provider' => 'zoho_books',
            'display_name' => 'Zoho Books',
            'status' => 'connected',
            'access_token' => 'access-secret-value',
            'refresh_token' => 'refresh-secret-value',
        ]);

        $this->assertNotSame('access-secret-value', $connection->getRawOriginal('access_token'));
        $this->assertNotSame('refresh-secret-value', $connection->getRawOriginal('refresh_token'));
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
        $this->assertSame('access-secret-value', $connection->fresh()->access_token);
    }

    private function fixture(bool $withMappings = true): array
    {
        [$actor, $organization] = $this->makeBillingActor();
        $customer = NexispCustomer::create([
            'organization_id' => $organization->id,
            'customer_number' => 'NXC-'.strtoupper(uniqid()),
            'name' => 'Accounting Test Customer',
            'phone' => '+254700123456',
            'status' => 'active',
        ]);
        $invoice = NexispInvoice::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'subscription_id' => null,
            'invoice_number' => 'INV-'.strtoupper(uniqid()),
            'status' => 'open',
            'amount' => '1500.00',
            'currency' => 'KES',
            'issued_at' => now(),
        ]);
        $connection = AccountingConnection::create([
            'organization_id' => $organization->id,
            'connected_by_user_id' => $actor->id,
            'provider' => 'zoho_books',
            'display_name' => 'Zoho Books',
            'external_account_id' => 'zoho-org-'.uniqid(),
            'status' => 'connected',
            'access_token' => 'encrypted-access-token',
            'refresh_token' => 'encrypted-refresh-token',
        ]);

        if ($withMappings) {
            foreach (['accounts_receivable', 'subscription_revenue'] as $mappingType) {
                AccountingAccountMapping::create([
                    'organization_id' => $organization->id,
                    'accounting_connection_id' => $connection->id,
                    'mapping_type' => $mappingType,
                    'local_code' => $mappingType,
                    'external_account_id' => 'external-'.$mappingType,
                    'external_account_name' => str_replace('_', ' ', $mappingType),
                    'enabled' => true,
                ]);
            }
        }

        return [$actor, $organization, $connection, $invoice];
    }

    private function makeBillingActor(): array
    {
        $organization = Organization::create([
            'name' => 'Accounting Tenant '.uniqid(),
            'slug' => 'accounting-'.uniqid(),
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);

        $actor = User::create([
            'name' => 'Accounting Billing Actor',
            'email' => 'accounting-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ]);

        $actor->organizations()->attach($organization->id, [
            'role' => 'billing',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$actor, $organization];
    }
}
