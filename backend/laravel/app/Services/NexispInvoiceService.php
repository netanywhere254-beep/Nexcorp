<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\NexispCustomer;
use App\Models\NexispInvoice;
use App\Models\NexispSubscription;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class NexispInvoiceService
{
    public function issueForSubscription(
        User $actor,
        Organization $organization,
        int $subscriptionId,
        string $idempotencyKey,
        ?Carbon $dueAt = null,
    ): NexispInvoice {
        Gate::forUser($actor)->authorize('manageBilling', $organization);

        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Use an 8–100 character idempotency key containing only letters, numbers, dots, underscores, colons or hyphens.',
            ]);
        }

        if ($dueAt !== null && $dueAt->isBefore(now())) {
            throw ValidationException::withMessages(['due_at' => 'The invoice due date cannot be in the past.']);
        }

        try {
            return DB::transaction(function () use ($actor, $organization, $subscriptionId, $idempotencyKey, $dueAt): NexispInvoice {
                $existing = NexispInvoice::query()
                    ->where('organization_id', $organization->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                $subscription = NexispSubscription::query()
                    ->where('organization_id', $organization->id)
                    ->lockForUpdate()
                    ->findOrFail($subscriptionId);

                if ($subscription->customer_id === null) {
                    throw ValidationException::withMessages([
                        'subscription' => 'A customer record must be linked before an invoice can be issued.',
                    ]);
                }

                $customer = NexispCustomer::query()
                    ->where('organization_id', $organization->id)
                    ->lockForUpdate()
                    ->findOrFail($subscription->customer_id);

                $amount = (string) $subscription->price_amount;
                $currency = strtoupper((string) $subscription->currency);

                if (! is_numeric($amount) || (float) $amount <= 0 || ! preg_match('/^[A-Z]{3}$/', $currency)) {
                    throw ValidationException::withMessages([
                        'subscription' => 'The subscription must have a positive amount and a valid three-letter currency.',
                    ]);
                }

                if ($existing !== null) {
                    $sameRequest = (int) $existing->subscription_id === (int) $subscription->id
                        && (int) $existing->customer_id === (int) $customer->id
                        && number_format((float) $existing->amount, 2, '.', '') === number_format((float) $amount, 2, '.', '')
                        && $existing->currency === $currency;

                    if (! $sameRequest) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => 'This idempotency key has already been used for a different invoice request.',
                        ]);
                    }

                    return $existing;
                }

                $issuedAt = now();
                $invoice = NexispInvoice::create([
                    'organization_id' => $organization->id,
                    'customer_id' => $customer->id,
                    'subscription_id' => $subscription->id,
                    'invoice_number' => 'NXC-INV-'.strtoupper((string) \Illuminate\Support\Str::ulid()),
                    'idempotency_key' => $idempotencyKey,
                    'status' => 'open',
                    'amount' => $amount,
                    'currency' => $currency,
                    'issued_at' => $issuedAt,
                    'due_at' => $dueAt,
                ]);

                AuditLog::create([
                    'organization_id' => $organization->id,
                    'actor_user_id' => $actor->id,
                    'action' => 'nexisp.invoice.issued',
                    'subject_type' => NexispInvoice::class,
                    'subject_id' => (string) $invoice->id,
                    'metadata' => [
                        'subscription_id' => $subscription->id,
                        'customer_id' => $customer->id,
                        'amount' => $amount,
                        'currency' => $currency,
                    ],
                ]);

                return $invoice;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request may win the organization-scoped idempotency key.
            $invoice = NexispInvoice::query()
                ->where('organization_id', $organization->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($invoice !== null
                && (int) $invoice->subscription_id === $subscriptionId) {
                return $invoice;
            }

            throw ValidationException::withMessages([
                'idempotency_key' => 'This idempotency key has already been used.',
            ]);
        }
    }
}
