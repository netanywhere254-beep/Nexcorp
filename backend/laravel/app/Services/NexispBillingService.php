<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BandwidthPlan;
use App\Models\NexispAccountingSetting;
use App\Models\NexispCustomer;
use App\Models\NexispInvoice;
use App\Models\NexispSubscription;
use App\Models\Organization;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class NexispBillingService
{
    public const SUBSCRIPTION_TRANSITIONS = [
        'pending_payment' => ['active', 'cancelled'],
        'active' => ['expired', 'suspended', 'cancelled'],
        'suspended' => ['active', 'cancelled'],
        'expired' => ['active'],
        'cancelled' => [],
    ];

    public const INVOICE_TRANSITIONS = [
        'open' => ['paid', 'partially_paid', 'void'],
        'partially_paid' => ['paid', 'void'],
        'paid' => [],
        'void' => [],
    ];

    public function __construct(private NexispAccountingService $accounting)
    {
    }

    public function subscribe(
        Organization $organization,
        NexispCustomer $customer,
        BandwidthPlan $plan,
        User $actor,
    ): NexispInvoice {
        Gate::forUser($actor)->authorize('manageBilling', $organization);

        if ((int) $customer->organization_id !== (int) $organization->id
            || (int) $plan->organization_id !== (int) $organization->id) {
            throw ValidationException::withMessages(['plan_id' => 'Customer and plan must belong to this organization.']);
        }
        if ($customer->status !== 'active') {
            throw ValidationException::withMessages(['customer' => 'Only active customers can subscribe.']);
        }
        if (! $plan->is_active) {
            throw ValidationException::withMessages(['plan_id' => 'This plan is not available.']);
        }

        return DB::transaction(function () use ($organization, $customer, $plan, $actor): NexispInvoice {
            NexispCustomer::query()->where('organization_id', $organization->id)
                ->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $duplicate = NexispSubscription::query()
                ->where('organization_id', $organization->id)
                ->where('customer_id', $customer->id)
                ->where('bandwidth_plan_id', $plan->id)
                ->where('status', 'pending_payment')
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'plan_id' => 'This customer already has an unpaid subscription to this plan.',
                ]);
            }

            $this->accounting->bootstrap($organization, $actor);
            $subscription = NexispSubscription::create([
                'organization_id' => $organization->id,
                'user_id' => $customer->user_id,
                'customer_id' => $customer->id,
                'bandwidth_plan_id' => $plan->id,
                'subscription_number' => 'NXS-'.Str::upper((string) Str::ulid()),
                'status' => 'pending_payment',
                'price_amount' => $plan->price,
                'currency' => strtoupper($plan->currency),
                'plan_snapshot' => [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'speed_mbps' => $plan->speed_mbps,
                    'validity_days' => $plan->validity_days,
                    'price' => $plan->price,
                    'currency' => strtoupper($plan->currency),
                ],
            ]);

            $invoice = $this->accounting->createInvoice($actor, $organization, [
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
                'currency' => strtoupper($plan->currency),
                'issued_at' => now()->toDateTimeString(),
                'due_at' => now()->addDay()->toDateTimeString(),
                'idempotency_key' => 'subscription-'.$subscription->id,
                'notes' => 'Subscription '.$subscription->subscription_number.' — '.$plan->name,
                'items' => [[
                    'description' => $plan->name.' ('.$plan->speed_mbps.' Mbps / '.$plan->validity_days.' days)',
                    'quantity' => 1,
                    'unit_price' => (string) $plan->price,
                ]],
            ]);

            $this->audit($organization->id, $actor, 'nexisp.subscription.created', $subscription, [
                'plan_id' => $plan->id,
                'invoice_id' => $invoice->id,
            ]);

            return $invoice->load('subscription', 'items');
        });
    }

    /**
     * Called by the verified payment-provider workflow. Never accept a raw callback amount here.
     * The payment transaction must already be persisted, verified and bound to this invoice.
     */
    public function settleInvoice(int $invoiceId, int $paymentTransactionId, User $actor): NexispInvoice
    {
        return DB::transaction(function () use ($invoiceId, $paymentTransactionId, $actor): NexispInvoice {
            $invoice = NexispInvoice::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('manageBilling', $invoice->organization);

            if ($invoice->status === 'paid') {
                return $invoice->load('subscription');
            }
            $payment = PaymentTransaction::query()
                ->where('organization_id', $invoice->organization_id)
                ->whereKey($paymentTransactionId)
                ->where('payable_type', NexispInvoice::class)
                ->where('payable_id', $invoice->id)
                ->where('status', 'succeeded')
                ->whereNotNull('verified_at')
                ->firstOrFail();

            if (strtoupper($payment->currency) !== strtoupper($invoice->currency)
                || $this->cents((string) $payment->amount) !== $this->cents((string) $invoice->amount)) {
                throw ValidationException::withMessages([
                    'payment' => 'Only a verified payment matching the full invoice amount and currency can activate a subscription.',
                ]);
            }

            $this->assertTransition(self::INVOICE_TRANSITIONS, $invoice->status, 'paid', 'invoice');
            $this->accounting->issueReceipt($actor, $invoice->organization, $invoice->id, $payment->id);

            $invoice->refresh();
            if ($invoice->status !== 'paid') {
                throw ValidationException::withMessages(['payment' => 'Invoice has not been fully settled.']);
            }

            if ($invoice->subscription_id !== null) {
                $subscription = NexispSubscription::query()
                    ->where('organization_id', $invoice->organization_id)
                    ->whereKey($invoice->subscription_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($subscription->status === 'pending_payment') {
                    $this->assertTransition(self::SUBSCRIPTION_TRANSITIONS, $subscription->status, 'active', 'subscription');
                    $days = max(1, (int) ($subscription->plan_snapshot['validity_days'] ?? 30));
                    $subscription->update([
                        'status' => 'active',
                        'activated_at' => now(),
                        'starts_at' => now(),
                        'ends_at' => now()->addDays($days),
                    ]);
                    $this->audit($invoice->organization_id, $actor, 'nexisp.subscription.activated', $subscription, [
                        'invoice_id' => $invoice->id,
                        'payment_transaction_id' => $payment->id,
                    ]);
                }
            }

            $this->audit($invoice->organization_id, $actor, 'nexisp.invoice.paid', $invoice, [
                'subscription_id' => $invoice->subscription_id,
                'payment_transaction_id' => $payment->id,
            ]);

            return $invoice->fresh(['subscription', 'items']);
        });
    }

    public function voidInvoice(int $invoiceId, User $actor): NexispInvoice
    {
        return DB::transaction(function () use ($invoiceId, $actor): NexispInvoice {
            $invoice = NexispInvoice::query()->lockForUpdate()->findOrFail($invoiceId);
            Gate::forUser($actor)->authorize('manageBilling', $invoice->organization);
            if ($invoice->status === 'void') {
                return $invoice->load('subscription');
            }

            $this->assertTransition(self::INVOICE_TRANSITIONS, $invoice->status, 'void', 'invoice');
            $invoice->update(['status' => 'void']);

            if ($invoice->subscription_id !== null) {
                $subscription = NexispSubscription::query()
                    ->where('organization_id', $invoice->organization_id)
                    ->whereKey($invoice->subscription_id)
                    ->lockForUpdate()
                    ->first();
                if ($subscription && $subscription->status === 'pending_payment') {
                    $subscription->update(['status' => 'cancelled']);
                    $this->audit($invoice->organization_id, $actor, 'nexisp.subscription.cancelled', $subscription, [
                        'invoice_id' => $invoice->id,
                    ]);
                }
            }

            $this->audit($invoice->organization_id, $actor, 'nexisp.invoice.voided', $invoice, []);
            return $invoice->fresh('subscription');
        });
    }

    private function assertTransition(array $map, string $from, string $to, string $label): void
    {
        if (! in_array($to, $map[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                $label => "Cannot move {$label} from {$from} to {$to}.",
            ]);
        }
    }

    private function cents(string $amount): int
    {
        if (! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amount)) {
            throw ValidationException::withMessages(['amount' => 'Invalid monetary amount.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function audit(int $organizationId, User $actor, string $action, $subject, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => $organizationId,
            'actor_user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => (string) $subject->id,
            'metadata' => $metadata,
        ]);
    }
}
