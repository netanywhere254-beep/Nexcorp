<?php

namespace App\Services;

use App\Contracts\PaymentProviderVerifier;
use App\Models\AuditLog;
use App\Models\NexispSubscription;
use App\Models\PaymentTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PaymentIntentService
{
    public const STATUS_INITIATED = 'initiated';
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public function createForSubscription(NexispSubscription $subscription, string $provider, ?string $idempotencyKey, int $actorUserId): PaymentTransaction
    {
        $provider = strtolower(trim($provider));
        if ($provider === '') {
            throw new InvalidArgumentException('Provider is required.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = PaymentTransaction::query()
                ->where('organization_id', $subscription->organization_id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                $this->assertSamePayable($existing, $subscription);
                return $existing;
            }
        } else {
            $idempotencyKey = null;
        }

        try {
            return DB::transaction(function () use ($subscription, $provider, $idempotencyKey, $actorUserId) {
                if ($idempotencyKey !== null) {
                    $existing = PaymentTransaction::query()
                        ->where('organization_id', $subscription->organization_id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        $this->assertSamePayable($existing, $subscription);
                        return $existing;
                    }
                }

                $tx = PaymentTransaction::create([
                    'organization_id' => $subscription->organization_id,
                    'user_id' => $subscription->user_id,
                    'payable_type' => $subscription->getMorphClass(),
                    'payable_id' => $subscription->getKey(),
                    'provider' => $provider,
                    'status' => self::STATUS_INITIATED,
                    'amount' => $subscription->price_amount,
                    'currency' => $subscription->currency,
                    'idempotency_key' => $idempotencyKey,
                ]);

                AuditLog::create([
                    'organization_id' => $subscription->organization_id,
                    'actor_user_id' => $actorUserId,
                    'action' => 'payment.intent_created',
                    'subject_type' => PaymentTransaction::class,
                    'subject_id' => (string) $tx->id,
                    'metadata' => [
                        'provider' => $provider,
                        'payable_type' => $tx->payable_type,
                        'payable_id' => $tx->payable_id,
                        'status' => $tx->status,
                    ],
                ]);

                return $tx;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey !== null) {
                $existing = PaymentTransaction::query()
                    ->where('organization_id', $subscription->organization_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
                $this->assertSamePayable($existing, $subscription);
                return $existing;
            }
            throw $e;
        }
    }

    public function verifyWithProvider(PaymentTransaction $transaction, array $payload, PaymentProviderVerifier $verifier, int $actorUserId): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $payload, $verifier, $actorUserId) {
            $tx = PaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($tx->status === self::STATUS_VERIFIED) {
                return $tx; // idempotent
            }

            $result = $verifier->verify($tx, $payload);
            if (!($result['verified'] ?? false)) {
                $tx->forceFill([
                    'status' => self::STATUS_FAILED,
                    'provider_payload' => $result['payload'] ?? $payload,
                ])->save();
                $this->audit($tx, $actorUserId, 'payment.verification_failed', ['provider' => $tx->provider]);
                return $tx;
            }

            // Do not trust provider amount/currency blindly; require match.
            $amount = (string) ($result['amount'] ?? $tx->amount);
            $currency = strtoupper((string) ($result['currency'] ?? $tx->currency));
            if ((string) $tx->amount !== number_format((float) $amount, 2, '.', '') || strtoupper($tx->currency) !== $currency) {
                $tx->forceFill([
                    'status' => self::STATUS_FAILED,
                    'provider_payload' => $result['payload'] ?? $payload,
                ])->save();
                $this->audit($tx, $actorUserId, 'payment.verification_mismatch', ['provider' => $tx->provider]);
                return $tx;
            }

            $tx->forceFill([
                'status' => self::STATUS_VERIFIED,
                'verified_at' => now(),
                'provider_reference' => $result['provider_reference'] ?? $tx->provider_reference,
                'provider_payload' => $result['payload'] ?? $payload,
            ])->save();

            $this->audit($tx, $actorUserId, 'payment.verified', [
                'provider' => $tx->provider,
                'provider_reference' => $tx->provider_reference,
            ]);

            return $tx;
        });
    }

    private function assertSamePayable(PaymentTransaction $existing, NexispSubscription $subscription): void
    {
        if ($existing->payable_type !== $subscription->getMorphClass() || (int) $existing->payable_id !== (int) $subscription->getKey()) {
            throw new InvalidArgumentException('Idempotency key already used for a different payable.');
        }
    }

    private function audit(PaymentTransaction $tx, int $actorUserId, string $action, array $metadata): void
    {
        AuditLog::create([
            'organization_id' => $tx->organization_id,
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'subject_type' => PaymentTransaction::class,
            'subject_id' => (string) $tx->id,
            'metadata' => $metadata,
        ]);
    }
}
