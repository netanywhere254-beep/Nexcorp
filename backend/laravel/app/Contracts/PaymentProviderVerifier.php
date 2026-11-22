<?php

namespace App\Contracts;

use App\Models\PaymentTransaction;

interface PaymentProviderVerifier
{
    /**
     * Verify a provider callback/payload for a known transaction.
     * Implementations must not trust client-supplied amounts or status.
     *
     * @return array{verified: bool, provider_reference?: string|null, amount?: string|int|float|null, currency?: string|null, status?: string|null, payload?: array<string,mixed>}
     */
    public function verify(PaymentTransaction $transaction, array $payload): array;
}
