<?php

namespace Tests\Feature;

use App\Models\BandwidthPlan;
use App\Models\LoopzaOrder;
use App\Models\LoopzaOrderItem;
use App\Models\NexispSubscription;
use App\Models\Organization;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_nexisp_plans_are_owned_by_an_organization_and_have_explicit_currency(): void
    {
        $organization = $this->makeOrganization();

        $plan = BandwidthPlan::create([
            'organization_id' => $organization->id,
            'code' => 'HOME-50',
            'name' => 'Home 50 Mbps',
            'speed_mbps' => 50,
            'price' => '1500.00',
            'currency' => 'KES',
            'validity_days' => 30,
            'is_active' => true,
        ]);

        $this->assertSame($organization->id, $plan->organization->id);
        $this->assertSame('1500.00', $plan->price);
        $this->assertSame('KES', $plan->currency);
    }

    public function test_loopza_products_are_tenant_scoped_and_track_stock_as_an_integer(): void
    {
        $organization = $this->makeOrganization();

        $product = Product::create([
            'organization_id' => $organization->id,
            'sku' => 'ROUTER-AX3000',
            'title' => 'Wireless Router AX3000',
            'description' => 'Wi-Fi 6 router',
            'price' => '8999.00',
            'currency' => 'KES',
            'stock_quantity' => 25,
            'status' => 'active',
        ]);

        $this->assertSame($organization->id, $product->organization->id);
        $this->assertSame(25, $product->stock_quantity);
        $this->assertSame('8999.00', $product->price);
    }

    public function test_loopza_order_keeps_a_product_snapshot_and_starts_unpaid(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser();
        $product = Product::create([
            'organization_id' => $organization->id,
            'sku' => 'CAT6-10M',
            'title' => 'Ethernet Cable Cat6 10m',
            'price' => '1250.00',
            'currency' => 'KES',
            'stock_quantity' => 100,
            'status' => 'active',
        ]);

        $order = LoopzaOrder::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'order_number' => 'LZ-TEST-0001',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'currency' => 'KES',
            'subtotal' => '2500.00',
            'total_amount' => '2500.00',
            'idempotency_key' => 'test-order-1',
        ]);

        $item = $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'title' => $product->title,
            'quantity' => 2,
            'unit_price' => '1250.00',
            'line_total' => '2500.00',
        ]);

        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame('Ethernet Cable Cat6 10m', $item->title);
        $this->assertSame(2, $item->quantity);
        $this->assertSame($order->id, $item->order->id);
    }

    public function test_nexisp_subscription_is_pending_payment_and_snapshots_plan_terms(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser();
        $plan = BandwidthPlan::create([
            'organization_id' => $organization->id,
            'code' => 'STARTER-30',
            'name' => 'Starter 30 Mbps',
            'speed_mbps' => 30,
            'price' => '1500.00',
            'currency' => 'KES',
            'validity_days' => 30,
            'is_active' => true,
        ]);

        $subscription = NexispSubscription::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'bandwidth_plan_id' => $plan->id,
            'subscription_number' => 'NX-TEST-0001',
            'status' => 'pending_payment',
            'price_amount' => '1500.00',
            'currency' => 'KES',
            'plan_snapshot' => [
                'code' => $plan->code,
                'name' => $plan->name,
                'speed_mbps' => $plan->speed_mbps,
                'validity_days' => $plan->validity_days,
            ],
        ]);

        $this->assertSame('pending_payment', $subscription->status);
        $this->assertNull($subscription->activated_at);
        $this->assertSame(30, $subscription->plan_snapshot['validity_days']);
    }

    public function test_payment_transaction_is_attached_to_a_tenant_owned_payable(): void
    {
        $organization = $this->makeOrganization();
        $user = $this->makeUser();
        $order = LoopzaOrder::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'order_number' => 'LZ-TEST-0002',
            'status' => 'pending',
            'payment_status' => 'unpaid',
            'currency' => 'KES',
            'subtotal' => '100.00',
            'total_amount' => '100.00',
        ]);

        $transaction = $order->paymentTransactions()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'provider' => 'manual_test',
            'status' => 'initiated',
            'amount' => '100.00',
            'currency' => 'KES',
        ]);

        $this->assertInstanceOf(PaymentTransaction::class, $transaction);
        $this->assertSame($order->id, $transaction->payable->id);
        $this->assertNull($transaction->verified_at);
    }

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Commerce Test '.uniqid(),
            'slug' => 'commerce-'.uniqid(),
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Commerce Test User',
            'email' => 'commerce-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ]);
    }
}
