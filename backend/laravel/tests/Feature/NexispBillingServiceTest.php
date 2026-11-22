<?php

namespace Tests\Feature;

use App\Models\BandwidthPlan;
use App\Models\NexispCustomer;
use App\Models\NexispInvoice;
use App\Models\NexispJournalEntry;
use App\Services\NexispAccountingService;
use App\Models\Organization;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\NexispBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NexispBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['nexisp.phone_hash_key' => 'nexisp-billing-test-key']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_subscription_creates_native_ledger_invoice_and_pending_subscription(): void
    {
        [$org, $actor, $customer, $plan] = $this->fixture();
        $invoice = app(NexispBillingService::class)->subscribe($org, $customer, $plan, $actor);

        $this->assertSame('open', $invoice->status);
        $this->assertSame('1500.00', $invoice->amount);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('pending_payment', $invoice->subscription->status);
        $this->assertSame('HOME-50', $invoice->subscription->plan_snapshot['code']);
        $this->assertDatabaseHas('nexisp_journal_entries', [
            'organization_id' => $org->id,
            'source_type' => NexispInvoice::class,
            'source_id' => $invoice->id,
            'status' => 'posted',
        ]);
    }

    public function test_duplicate_unpaid_subscription_is_rejected(): void
    {
        [$org, $actor, $customer, $plan] = $this->fixture();
        $service = app(NexispBillingService::class);
        $service->subscribe($org, $customer, $plan, $actor);

        $this->expectException(ValidationException::class);
        $service->subscribe($org, $customer, $plan, $actor);
    }

    public function test_only_successful_verified_matching_payment_activates_subscription_once(): void
    {
        Carbon::setTestNow('2026-12-01 10:00:00');
        [$org, $actor, $customer, $plan] = $this->fixture();
        $service = app(NexispBillingService::class);
        $invoice = $service->subscribe($org, $customer, $plan, $actor);

        $payment = PaymentTransaction::create([
            'organization_id' => $org->id,
            'user_id' => $actor->id,
            'payable_type' => NexispInvoice::class,
            'payable_id' => $invoice->id,
            'provider' => 'mpesa',
            'provider_reference' => 'NXS-'.uniqid(),
            'idempotency_key' => 'PAY-'.uniqid(),
            'status' => 'succeeded',
            'amount' => '1500.00',
            'currency' => 'KES',
            'verified_at' => now(),
        ]);

        $paid = $service->settleInvoice($org, $invoice->id, $payment->id, $actor);
        $this->assertSame('paid', $paid->status);
        $this->assertSame('active', $paid->subscription->status);
        $this->assertTrue($paid->subscription->ends_at->equalTo(Carbon::parse('2026-12-31 10:00:00')));
        $this->assertDatabaseHas('nexisp_receipts', [
            'organization_id' => $org->id,
            'invoice_id' => $invoice->id,
            'payment_transaction_id' => $payment->id,
            'amount' => '1500.00',
        ]);

        Carbon::setTestNow('2026-12-02 10:00:00');
        $again = $service->settleInvoice($invoice->id, $payment->id, $actor);
        $this->assertTrue($again->subscription->activated_at->equalTo(Carbon::parse('2026-12-01 10:00:00')));
        $this->assertDatabaseCount('nexisp_receipts', 1);
    }

    public function test_subscription_activates_after_verified_final_installment(): void
    {
        [$org, $actor, $customer, $plan] = $this->fixture();
        $billing = app(NexispBillingService::class);
        $invoice = $billing->subscribe($org, $customer, $plan, $actor);

        $firstPayment = PaymentTransaction::create([
            'organization_id' => $org->id,
            'user_id' => $actor->id,
            'payable_type' => NexispInvoice::class,
            'payable_id' => $invoice->id,
            'provider' => 'mpesa',
            'provider_reference' => 'INSTALLMENT-1-'.uniqid(),
            'idempotency_key' => 'INSTALLMENT-1-'.uniqid(),
            'status' => 'succeeded',
            'amount' => '500.00',
            'currency' => 'KES',
            'verified_at' => now(),
        ]);
        app(NexispAccountingService::class)->issueReceipt($actor, $org, $invoice->id, $firstPayment->id);
        $this->assertSame('partially_paid', $invoice->fresh()->status);

        $finalPayment = PaymentTransaction::create([
            'organization_id' => $org->id,
            'user_id' => $actor->id,
            'payable_type' => NexispInvoice::class,
            'payable_id' => $invoice->id,
            'provider' => 'mpesa',
            'provider_reference' => 'INSTALLMENT-2-'.uniqid(),
            'idempotency_key' => 'INSTALLMENT-2-'.uniqid(),
            'status' => 'succeeded',
            'amount' => '1000.00',
            'currency' => 'KES',
            'verified_at' => now(),
        ]);

        $settled = $billing->settleInvoice($invoice->id, $finalPayment->id, $actor);
        $this->assertSame('paid', $settled->status);
        $this->assertSame('active', $settled->subscription->status);
        $this->assertDatabaseCount('nexisp_receipts', 2);
    }

    public function test_pending_or_mismatched_payment_cannot_activate_subscription(): void
    {
        [$org, $actor, $customer, $plan] = $this->fixture();
        $service = app(NexispBillingService::class);
        $invoice = $service->subscribe($org, $customer, $plan, $actor);

        $payment = PaymentTransaction::create([
            'organization_id' => $org->id,
            'user_id' => $actor->id,
            'payable_type' => NexispInvoice::class,
            'payable_id' => $invoice->id,
            'provider' => 'mpesa',
            'provider_reference' => 'NXS-'.uniqid(),
            'idempotency_key' => 'PAY-'.uniqid(),
            'status' => 'initiated',
            'amount' => '1500.00',
            'currency' => 'KES',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->settleInvoice($invoice->id, $payment->id, $actor);
    }

    public function test_voiding_invoice_cancels_only_pending_subscription(): void
    {
        [$org, $actor, $customer, $plan] = $this->fixture();
        $service = app(NexispBillingService::class);
        $invoice = $service->subscribe($org, $customer, $plan, $actor);
        $voided = $service->voidInvoice($org, $invoice->id, $actor);

        $this->assertSame('void', $voided->status);
        $this->assertSame('cancelled', $voided->subscription->status);
        $original = NexispJournalEntry::where('organization_id', $org->id)
            ->where('source_type', NexispInvoice::class)->where('source_id', $invoice->id)
            ->whereNull('reversal_of_entry_id')->firstOrFail();
        $reversal = NexispJournalEntry::where('organization_id', $org->id)
            ->where('reversal_of_entry_id', $original->id)->firstOrFail();
        $this->assertSame('posted', $reversal->status);
        $this->assertSame($original->total_debit, $reversal->total_credit);
        $this->assertSame($original->total_credit, $reversal->total_debit);
    }

    public function test_voided_invoice_cannot_receive_a_late_payment_receipt(): void
    {
        [$org, $actor, $customer, $plan] = $this->fixture();
        $billing = app(NexispBillingService::class);
        $invoice = $billing->subscribe($org, $customer, $plan, $actor);
        $billing->voidInvoice($invoice->id, $actor);

        $payment = PaymentTransaction::create([
            'organization_id' => $org->id,
            'user_id' => $actor->id,
            'payable_type' => NexispInvoice::class,
            'payable_id' => $invoice->id,
            'provider' => 'mpesa',
            'provider_reference' => 'LATE-'.uniqid(),
            'idempotency_key' => 'LATE-'.uniqid(),
            'status' => 'succeeded',
            'amount' => '1500.00',
            'currency' => 'KES',
            'verified_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(NexispAccountingService::class)->issueReceipt($actor, $org, $invoice->id, $payment->id);
    }

    public function test_invoice_with_partial_receipt_cannot_be_voided_without_refund_flow(): void
    {
        [$org, $actor, $customer, $plan] = $this->fixture();
        $billing = app(NexispBillingService::class);
        $invoice = $billing->subscribe($org, $customer, $plan, $actor);
        $payment = PaymentTransaction::create([
            'organization_id' => $org->id,
            'user_id' => $actor->id,
            'payable_type' => NexispInvoice::class,
            'payable_id' => $invoice->id,
            'provider' => 'mpesa',
            'provider_reference' => 'PARTIAL-'.uniqid(),
            'idempotency_key' => 'PARTIAL-'.uniqid(),
            'status' => 'succeeded',
            'amount' => '500.00',
            'currency' => 'KES',
            'verified_at' => now(),
        ]);
        app(NexispAccountingService::class)->issueReceipt($actor, $org, $invoice->id, $payment->id);

        $this->expectException(ValidationException::class);
        $billing->voidInvoice($org, $invoice->id, $actor);
    }

    private function fixture(): array
    {
        $org = Organization::create([
            'name' => 'NexISP Billing '.uniqid(),
            'slug' => 'nexisp-billing-'.uniqid(),
            'type' => 'business',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
        $actor = User::create([
            'name' => 'Billing Admin',
            'email' => 'nexisp-billing-'.uniqid().'@example.test',
            'password' => 'Correct-Horse-42!',
            'status' => 'active',
        ]);
        $actor->organizations()->attach($org->id, [
            'role' => 'billing',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $customer = NexispCustomer::create([
            'organization_id' => $org->id,
            'customer_number' => 'NXC-'.strtoupper(uniqid()),
            'name' => 'Test Customer',
            'phone' => '+254700'.random_int(100000, 999999),
            'status' => 'active',
        ]);
        $plan = BandwidthPlan::create([
            'organization_id' => $org->id,
            'code' => 'HOME-50',
            'name' => 'Home 50 Mbps',
            'speed_mbps' => 50,
            'price' => '1500.00',
            'currency' => 'KES',
            'validity_days' => 30,
            'is_active' => true,
        ]);

        return [$org, $actor, $customer, $plan];
    }
}
