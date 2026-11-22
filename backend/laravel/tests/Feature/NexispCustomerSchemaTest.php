<?php

namespace Tests\Feature;

use App\Models\NexispCustomer;
use App\Models\NexispInvoice;
use App\Models\Organization;
use App\Models\PaymentCallback;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NexispCustomerSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_exists_without_a_login_and_phone_is_encrypted_at_rest(): void
    {
        $org = $this->makeOrganization();

        $customer = NexispCustomer::create([
            'organization_id' => $org->id,
            'customer_number' => 'C-0001',
            'name' => 'Walk-in Customer',
            'phone' => '+254700111222',
        ]);

        $raw = DB::table('nexisp_customers')->where('id', $customer->id)->value('phone');

        $this->assertNull($customer->user_id);
        $this->assertStringNotContainsString('254700111222', $raw);
        $this->assertSame('+254700111222', $customer->fresh()->phone);
        $this->assertArrayNotHasKey('phone_hash', $customer->fresh()->toArray());
    }

    public function test_same_phone_is_allowed_in_another_organization(): void
    {
        $a = $this->makeOrganization();
        $b = $this->makeOrganization();

        NexispCustomer::create(['organization_id' => $a->id, 'customer_number' => 'C-A1',
            'name' => 'A', 'phone' => '+254700111222']);
        $other = NexispCustomer::create(['organization_id' => $b->id, 'customer_number' => 'C-B1',
            'name' => 'B', 'phone' => '+254700111222']);

        $this->assertNotNull($other->id);
    }

    public function test_same_phone_in_the_same_organization_is_rejected_even_if_formatted_differently(): void
    {
        $org = $this->makeOrganization();

        NexispCustomer::create(['organization_id' => $org->id, 'customer_number' => 'C-1',
            'name' => 'First', 'phone' => '+254700111222']);

        $this->expectException(QueryException::class);

        NexispCustomer::create(['organization_id' => $org->id, 'customer_number' => 'C-2',
            'name' => 'Second', 'phone' => '+254 700 111 222']);
    }

    public function test_duplicate_callback_payload_for_a_provider_is_rejected_and_payload_is_encrypted(): void
    {
        $row = [
            'provider' => 'mpesa',
            'external_id' => 'ws_CO_TEST_1',
            'payload_hash' => hash('sha256', 'raw-body-1'),
            'payload' => ['Body' => ['stkCallback' => ['ResultCode' => 0, 'PhoneNumber' => '254700111222']]],
            'received_at' => now(),
        ];

        $callback = PaymentCallback::create($row);
        $raw = DB::table('payment_callbacks')->where('id', $callback->id)->value('payload');

        $this->assertSame('received', $callback->fresh()->status);
        $this->assertStringNotContainsString('254700111222', $raw);
        $this->assertSame($row['payload'], $callback->fresh()->payload);
        $this->assertArrayNotHasKey('payload', $callback->fresh()->toArray());

        $this->expectException(QueryException::class);
        PaymentCallback::create($row);
    }

    public function test_invoice_cannot_reference_a_customer_from_another_organization(): void
    {
        $invoiceOrg = $this->makeOrganization();
        $otherOrg = $this->makeOrganization();
        $customer = NexispCustomer::create([
            'organization_id' => $otherOrg->id,
            'customer_number' => 'C-CROSS-TENANT',
            'name' => 'Other Tenant Customer',
            'phone' => '+254722333444',
        ]);

        $this->expectException(QueryException::class);

        DB::table('nexisp_invoices')->insert([
            'organization_id' => $invoiceOrg->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-CROSS-TENANT',
            'status' => 'open',
            'amount' => '1500.00',
            'currency' => 'KES',
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_payment_idempotency_key_is_unique_per_organization_not_globally(): void
    {
        $a = $this->makeOrganization();
        $b = $this->makeOrganization();

        $this->insertPayment($a->id, 'key-1');
        $this->insertPayment($b->id, 'key-1');

        $this->assertSame(2, DB::table('payment_transactions')->where('idempotency_key', 'key-1')->count());

        $this->expectException(QueryException::class);
        $this->insertPayment($a->id, 'key-1');
    }

    public function test_walk_in_subscription_can_be_linked_to_customer_without_a_user(): void
    {
        $org = $this->makeOrganization();
        $customer = NexispCustomer::create([
            'organization_id' => $org->id,
            'customer_number' => 'C-SUB-1',
            'name' => 'Home Internet Customer',
            'phone' => '+254711222333',
        ]);

        $subscriptionId = DB::table('nexisp_subscriptions')->insertGetId([
            'organization_id' => $org->id,
            'user_id' => null,
            'customer_id' => $customer->id,
            'subscription_number' => 'SUB-WALKIN-1',
            'status' => 'pending_payment',
            'price_amount' => '1500.00',
            'currency' => 'KES',
            'plan_snapshot' => json_encode(['name' => 'Home 30 Day']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($customer->id, DB::table('nexisp_subscriptions')->where('id', $subscriptionId)->value('customer_id'));
    }

    private function insertPayment(int $organizationId, string $key): void
    {
        DB::table('payment_transactions')->insert([
            'organization_id' => $organizationId,
            'user_id' => null,
            'payable_type' => 'test',
            'payable_id' => 1,
            'provider' => 'mpesa',
            'idempotency_key' => $key,
            'status' => 'initiated',
            'amount' => '100.00',
            'currency' => 'KES',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeOrganization(): Organization
    {
        return Organization::create([
            'name' => 'Customer Test '.uniqid(),
            'slug' => 'customer-'.uniqid(),
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
    }
}
