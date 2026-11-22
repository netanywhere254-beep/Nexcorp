<?php

namespace Tests\Feature;

use App\Contracts\PaymentProviderVerifier;
use App\Models\BandwidthPlan;
use App\Models\NexispSubscription;
use App\Models\Organization;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\PaymentIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NexispPaymentIntentTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_manager_can_create_payment_intent_with_server_amount(): void
    {
        [$org, $billing] = $this->orgWithUser('billing');
        $sub = $this->makeSubscription($org, '4500.00', 'KES');

        $response = $this->actingAs($billing)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson("/nexisp/subscriptions/{$sub->id}/payments", [
                'provider' => 'mpesa',
                'idempotency_key' => 'pay-001',
                'amount' => '1.00',
                'currency' => 'USD',
                'status' => 'verified',
            ])
            ->assertCreated()
            ->assertJsonPath('payment.provider', 'mpesa')
            ->assertJsonPath('payment.status', 'initiated')
            ->assertJsonPath('payment.amount', '4500.00')
            ->assertJsonPath('payment.currency', 'KES')
            ->assertJsonPath('payment.payable_type', $sub->getMorphClass())
            ->assertJsonPath('payment.payable_id', $sub->id);

        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $org->id,
            'action' => 'payment.intent_created',
        ]);
    }

    public function test_payment_intent_creation_is_idempotent(): void
    {
        [$org, $billing] = $this->orgWithUser('billing');
        $sub = $this->makeSubscription($org);

        $payload = ['provider' => 'mpesa', 'idempotency_key' => 'same-key'];
        $first = $this->actingAs($billing)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson("/nexisp/subscriptions/{$sub->id}/payments", $payload)
            ->assertCreated();
        $second = $this->actingAs($billing)
            ->withSession(['active_organization_id' => $org->id])
            ->postJson("/nexisp/subscriptions/{$sub->id}/payments", $payload)
            ->assertOk();

        $this->assertSame($first->json('payment.id'), $second->json('payment.id'));
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    public function test_payment_intent_is_tenant_scoped_and_requires_billing_role(): void
    {
        [$orgA] = $this->orgWithUser('billing', 'org-a');
        [$orgB, $managerB] = $this->orgWithUser('manager', 'org-b');
        [$orgB2, $billingB] = $this->orgWithUser('billing', 'org-b2');

        $subA = $this->makeSubscription($orgA);

        // manager lacks manageBilling in orgB, so request is forbidden before
        // tenant resource lookup.
        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->postJson("/nexisp/subscriptions/{$subA->id}/payments", ['provider' => 'mpesa'])
            ->assertForbidden();

        $this->actingAs($managerB)
            ->withSession(['active_organization_id' => $orgB->id])
            ->getJson('/nexisp/payments')
            ->assertForbidden();

        $tx = PaymentTransaction::create([
            'organization_id' => $orgA->id,
            'user_id' => $subA->user_id,
            'payable_type' => $subA->getMorphClass(),
            'payable_id' => $subA->id,
            'provider' => 'mpesa',
            'status' => 'initiated',
            'amount' => '4500.00',
            'currency' => 'KES',
        ]);

        $this->actingAs($billingB)
            ->withSession(['active_organization_id' => $orgB2->id])
            ->getJson('/nexisp/payments/'.$tx->id)
            ->assertNotFound();
    }

    public function test_verification_only_marks_paid_when_provider_is_verified_and_amount_matches(): void
    {
        [$org, $billing] = $this->orgWithUser('billing');
        $sub = $this->makeSubscription($org, '4500.00', 'KES');
        $service = app(PaymentIntentService::class);
        $tx = $service->createForSubscription($sub, 'mpesa', 'verify-1', $billing->id);

        $verified = $service->verifyWithProvider($tx, ['raw' => 'callback'], new class implements PaymentProviderVerifier {
            public function verify(PaymentTransaction $transaction, array $payload): array
            {
                return ['verified' => true, 'provider_reference' => 'MPESA123', 'amount' => '4500.00', 'currency' => 'KES', 'payload' => ['ok' => true]];
            }
        }, $billing->id);

        $this->assertSame('verified', $verified->status);
        $this->assertSame('MPESA123', $verified->provider_reference);
        $this->assertNotNull($verified->verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.verified', 'subject_id' => (string) $tx->id]);
    }

    public function test_verification_rejects_amount_or_currency_mismatch_and_is_idempotent_when_verified(): void
    {
        [$org, $billing] = $this->orgWithUser('billing');
        $sub = $this->makeSubscription($org, '4500.00', 'KES');
        $service = app(PaymentIntentService::class);
        $tx = $service->createForSubscription($sub, 'mpesa', 'verify-2', $billing->id);

        $failed = $service->verifyWithProvider($tx, ['raw' => 'bad'], new class implements PaymentProviderVerifier {
            public function verify(PaymentTransaction $transaction, array $payload): array
            {
                return ['verified' => true, 'provider_reference' => 'MPESA-BAD', 'amount' => '1.00', 'currency' => 'KES', 'payload' => ['bad' => true]];
            }
        }, $billing->id);

        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.verification_mismatch', 'subject_id' => (string) $tx->id]);

        // A new transaction can be verified and then stays idempotent.
        $tx2 = $service->createForSubscription($sub, 'mpesa', 'verify-3', $billing->id);
        $okVerifier = new class implements PaymentProviderVerifier {
            public function verify(PaymentTransaction $transaction, array $payload): array
            {
                return ['verified' => true, 'provider_reference' => 'MPESA-OK', 'amount' => '4500.00', 'currency' => 'KES'];
            }
        };
        $first = $service->verifyWithProvider($tx2, [], $okVerifier, $billing->id);
        $second = $service->verifyWithProvider($first, [], $okVerifier, $billing->id);
        $this->assertSame('verified', $second->status);
        $this->assertSame($first->verified_at?->toIso8601String(), $second->verified_at?->toIso8601String());
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
