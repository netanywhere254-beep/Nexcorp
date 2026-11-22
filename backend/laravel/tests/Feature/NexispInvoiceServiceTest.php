<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NexispCustomer;
use App\Models\NexispInvoice;
use App\Models\NexispSubscription;
use App\Models\Organization;
use App\Models\User;
use App\Services\NexispInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NexispInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_is_issued_from_the_tenant_subscription_snapshot(): void
    {
        [$actor, $organization, $subscription] = $this->makeSubscription();

        $invoice = app(NexispInvoiceService::class)->issueForSubscription(
            $actor,
            $organization,
            $subscription->id,
            'invoice-create-0001',
        );

        $this->assertSame($organization->id, $invoice->organization_id);
        $this->assertSame($subscription->customer_id, $invoice->customer_id);
        $this->assertSame($subscription->id, $invoice->subscription_id);
        $this->assertSame('1500.00', $invoice->amount);
        $this->assertSame('KES', $invoice->currency);
        $this->assertSame('open', $invoice->status);
        $this->assertNotEmpty($invoice->invoice_number);
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'actor_user_id' => $actor->id,
            'action' => 'nexisp.invoice.issued',
            'subject_id' => (string) $invoice->id,
        ]);
    }

    public function test_same_idempotency_key_returns_the_existing_invoice_without_duplicate_audit(): void
    {
        [$actor, $organization, $subscription] = $this->makeSubscription();
        $service = app(NexispInvoiceService::class);

        $first = $service->issueForSubscription($actor, $organization, $subscription->id, 'invoice-retry-0001');
        $second = $service->issueForSubscription($actor, $organization, $subscription->id, 'invoice-retry-0001');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('nexisp_invoices', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_idempotency_key_cannot_be_reused_for_another_subscription(): void
    {
        [$actor, $organization, $subscription] = $this->makeSubscription();
        $secondCustomer = $this->makeCustomer($organization, '+254711222333');
        $secondSubscription = $this->makeSubscriptionRecord($organization, $secondCustomer);

        $service = app(NexispInvoiceService::class);
        $service->issueForSubscription($actor, $organization, $subscription->id, 'invoice-reuse-0001');

        try {
            $service->issueForSubscription($actor, $organization, $secondSubscription->id, 'invoice-reuse-0001');
            $this->fail('An idempotency key must not create a second invoice for a different subscription.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertDatabaseCount('nexisp_invoices', 1);
    }

    public function test_invoice_creation_cannot_cross_organization_boundaries(): void
    {
        [$actor, $organization] = $this->makeBillingActor();
        [$otherActor, $otherOrganization, $foreignSubscription] = $this->makeSubscription();

        try {
            app(NexispInvoiceService::class)->issueForSubscription(
                $actor,
                $organization,
                $foreignSubscription->id,
                'invoice-cross-tenant-01',
            );
            $this->fail('A subscription belonging to another organization must not be invoiced.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertDatabaseCount('nexisp_invoices', 0);
        }
    }

    public function test_customer_must_be_linked_to_subscription_before_invoice_issue(): void
    {
        [$actor, $organization] = $this->makeBillingActor();
        $subscription = NexispSubscription::create([
            'organization_id' => $organization->id,
            'user_id' => null,
            'customer_id' => null,
            'subscription_number' => 'NXS-'.strtoupper(uniqid()),
            'status' => 'pending_payment',
            'price_amount' => '1500.00',
            'currency' => 'KES',
            'plan_snapshot' => ['name' => 'Home 10 Mbps', 'speed_mbps' => 10],
        ]);

        try {
            app(NexispInvoiceService::class)->issueForSubscription(
                $actor,
                $organization,
                $subscription->id,
                'invoice-no-customer-01',
            );
            $this->fail('An invoice must have a linked customer.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subscription', $exception->errors());
        }

        $this->assertDatabaseCount('nexisp_invoices', 0);
    }

    private function makeSubscription(): array
    {
        [$actor, $organization] = $this->makeBillingActor();
        $customer = $this->makeCustomer($organization, '+254700111222');

        return [$actor, $organization, $this->makeSubscriptionRecord($organization, $customer)];
    }

    private function makeSubscriptionRecord(Organization $organization, NexispCustomer $customer): NexispSubscription
    {
        return NexispSubscription::create([
            'organization_id' => $organization->id,
            'user_id' => null,
            'customer_id' => $customer->id,
            'subscription_number' => 'NXS-'.strtoupper(uniqid()),
            'status' => 'pending_payment',
            'price_amount' => '1500.00',
            'currency' => 'KES',
            'plan_snapshot' => ['name' => 'Home 10 Mbps', 'speed_mbps' => 10],
        ]);
    }

    private function makeCustomer(Organization $organization, string $phone): NexispCustomer
    {
        return NexispCustomer::create([
            'organization_id' => $organization->id,
            'customer_number' => 'NXC-'.strtoupper(uniqid()),
            'name' => 'Invoice Customer',
            'phone' => $phone,
            'status' => 'active',
        ]);
    }

    private function makeBillingActor(): array
    {
        $organization = Organization::create([
            'name' => 'Invoice Tenant '.uniqid(),
            'slug' => 'invoice-'.uniqid(),
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);

        $actor = User::create([
            'name' => 'Billing Actor',
            'email' => 'billing-'.uniqid().'@example.test',
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
