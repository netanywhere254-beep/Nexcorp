<?php

namespace Tests\Feature;

use App\Contracts\PaymentProviderVerifier;
use App\Models\BandwidthPlan;
use App\Models\NexispSubscription;
use App\Models\Organization;
use App\Models\PaymentReceipt;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\PaymentIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexispPaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_payment_creates_receipt_once(): void
    {
        [$org, $billing] = $this->orgWithUser('billing');
        $sub = $this->makeSubscription($org, '4500.00', 'KES');
        $service = app(PaymentIntentService::class);
        $tx = $service->createForSubscription($sub, 'mpesa', 'rcpt-1', $billing->id);

        $verifier = new class implements PaymentProviderVerifier {
            public function verify(PaymentTransaction $transaction, array $payload): array
            {
                return ['verified' => true, 'provider_reference' => 'MPESA-R1', 'amount' => '4500.00', 'currency' => 'KES'];
            }
        };

        $service->verifyWithProvider($tx, [], $verifier, $billing->id);
        $service->verifyWithProvider($tx->fresh(), [], $verifier, $billing->id);

        $this->assertDatabaseCount('payment_receipts', 1);
        $receipt = PaymentReceipt::firstOrFail();
        $this->assertSame($tx->id, $receipt->payment_transaction_id);
        $this->assertSame('4500.00', (string) $receipt->amount);
        $this->assertSame('KES', $receipt->currency);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.receipt_issued', 'subject_id' => (string) $tx->id]);
    }

    public function test_receipt_endpoints_are_tenant_scoped_and_require_billing_role(): void
    {
        [$orgA, $billingA] = $this->orgWithUser('billing', 'org-a');
        [$orgB, $managerB] = $this->orgWithUser('manager', 'org-b');
        [$orgB2, $billingB2] = $this->orgWithUser('billing', 'org-b2');

        $sub = $this->makeSubscription($orgA, '3000.00', 'KES');
        $service = app(PaymentIntentService::class);
        $tx = $service->createForSubscription($sub, 'mpesa', 'rcpt-2', $billingA->id);
        $service->verifyWithProvider($tx, [], new class implements PaymentProviderVerifier {
            public function verify(PaymentTransaction $transaction, array $payload): array
            {
                return ['verified' => true, 'provider_reference' => 'MPESA-R2', 'amount' => '3000.00', 'currency' => 'KES'];
            }
        }, $billingA->id);

        $receipt = PaymentReceipt::firstOrFail();

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->getJson('/nexisp/receipts')
            ->assertForbidden();

        $this->actingAs($billingB2)
            ->withSession(['active_organization_id' => $orgB2->id])
            ->getJson('/nexisp/receipts/'.$receipt->id)
            ->assertNotFound();

        $this->actingAs($billingA)
            ->withSession(['active_organization_id' => $orgA->id])
            ->getJson('/nexisp/receipts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.receipt_number', $receipt->receipt_number);
    }

    private function makeSubscription(Organization $org, string $price = '4500.00', string $currency = 'KES'): NexispSubscription
    {
        $user = $this->makeUser();
        $plan = BandwidthPlan::create([
            'organization_id' => $org->id,
            'code' => 'PLAN-'.uniqid(),
            'name' => 'Plan',
            'speed_mbps' => 10,
            'price' => $price,
            'currency' => $currency,
            'validity_days' => 30,
            'is_active' => true,
        ]);

        return NexispSubscription::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'bandwidth_plan_id' => $plan->id,
            'subscription_number' => 'SUB-'.uniqid(),
            'status' => 'pending_payment',
            'price_amount' => $price,
            'currency' => $currency,
            'plan_snapshot' => ['code' => $plan->code, 'price' => $price, 'currency' => $currency],
        ]);
    }

    private function makeUser(): User
    {
        $user = User::create([
            'name' => 'User',
            'email' => uniqid('u').'@example.test',
            'password' => 'password',
            'status' => 'active',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        return $user;
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function orgWithUser(string $role, string $slug = 'org'): array
    {
        $org = Organization::create([
            'name' => 'Org '.strtoupper($slug),
            'slug' => $slug.'-'.uniqid(),
            'type' => 'isp',
            'status' => 'active',
            'country_code' => 'KE',
            'timezone' => 'Africa/Nairobi',
        ]);
        $user = $this->makeUser();
        $org->members()->create([
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        return [$org, $user];
    }
}
