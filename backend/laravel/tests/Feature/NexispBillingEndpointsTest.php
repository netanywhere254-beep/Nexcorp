<?php

namespace Tests\Feature;

use App\Models\BandwidthPlan;
use App\Models\NexispCustomer;
use App\Models\NexispInvoice;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexispBillingEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nexisp.phone_hash_key' => 'nexisp-billing-endpoint-test-key']);
    }

    public function test_billing_role_can_subscribe_using_server_side_plan_price(): void
    {
        [$user, $org] = $this->memberWithRole('billing');
        $customer = $this->makeCustomer($org);
        $plan = $this->makePlan($org);

        $this->asMember($user, $org)
            ->postJson("/nexisp/customers/{$customer->id}/subscriptions", [
                'plan_id' => $plan->id,
                'amount' => '1.00',
                'organization_id' => Organization::query()->where('id', '!=', $org->id)->value('id'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.amount', '1500.00')
            ->assertJsonPath('data.subscription.status', 'pending_payment');

        $this->assertDatabaseHas('nexisp_invoices', [
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
            'amount' => '1500.00',
        ]);
    }

    public function test_support_can_read_invoices_but_cannot_subscribe_or_void(): void
    {
        [$support, $org] = $this->memberWithRole('support');
        $customer = $this->makeCustomer($org);
        $plan = $this->makePlan($org);

        $this->asMember($support, $org)->getJson('/nexisp/invoices')->assertOk();
        $this->asMember($support, $org)
            ->postJson("/nexisp/customers/{$customer->id}/subscriptions", ['plan_id' => $plan->id])
            ->assertForbidden();
    }

    public function test_cross_tenant_customer_and_plan_are_not_found(): void
    {
        [$user, $org] = $this->memberWithRole('owner');
        $other = $this->makeOrganization();
        $foreignCustomer = $this->makeCustomer($other);
        $foreignPlan = $this->makePlan($other);

        $this->asMember($user, $org)
            ->postJson("/nexisp/customers/{$foreignCustomer->id}/subscriptions", ['plan_id' => $foreignPlan->id])
            ->assertNotFound();
        $this->asMember($user, $org)
            ->postJson("/nexisp/customers/{$foreignCustomer->id}/subscriptions", ['plan_id' => $this->makePlan($org)->id])
            ->assertNotFound();
        $this->asMember($user, $org)
            ->postJson("/nexisp/customers/{$this->makeCustomer($org)->id}/subscriptions", ['plan_id' => $foreignPlan->id])
            ->assertNotFound();
    }

    public function test_invoice_list_filters_by_customer_and_status(): void
    {
        [$user, $org] = $this->memberWithRole('billing');
        $customer = $this->makeCustomer($org);
        $plan = $this->makePlan($org);
        $invoice = app(\App\Services\NexispBillingService::class)->subscribe($org, $customer, $plan, $user);

        $this->asMember($user, $org)->getJson('/nexisp/invoices?status=open')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->asMember($user, $org)->getJson('/nexisp/invoices?status=paid')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->asMember($user, $org)->getJson("/nexisp/invoices?customer_id={$customer->id}")
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseHas('nexisp_invoices', ['id' => $invoice->id, 'organization_id' => $org->id]);
    }

    private function asMember(User $user, Organization $org): static
    {
        return $this->actingAs($user)->withSession(['active_organization_id' => $org->id]);
    }

    private function memberWithRole(string $role): array
    {
        $org = $this->makeOrganization();
        $user = User::create([
            'name' => 'Billing API User',
            'email' => 'billing-api-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->organizations()->attach($org->id, [
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        return [$user, $org];
    }

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Billing API Org '.uniqid(),
            'slug' => 'billing-api-'.uniqid(),
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
    }

    private function makeCustomer(Organization $org): NexispCustomer
    {
        return NexispCustomer::create([
            'organization_id' => $org->id,
            'customer_number' => 'NXC-'.strtoupper(uniqid()),
            'name' => 'Billing API Customer',
            'phone' => '+254700'.random_int(100000, 999999),
            'status' => 'active',
        ]);
    }

    private function makePlan(Organization $org): BandwidthPlan
    {
        return BandwidthPlan::create([
            'organization_id' => $org->id,
            'code' => 'HOME-50',
            'name' => 'Home 50 Mbps',
            'speed_mbps' => 50,
            'price' => '1500.00',
            'currency' => 'KES',
            'validity_days' => 30,
            'is_active' => true,
        ]);
    }
}
