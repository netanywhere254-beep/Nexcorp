<?php

namespace App\Contracts;

use App\Models\AccountingConnection;

interface AccountingProvider
{
    public function key(): string;

    /**
     * Create or update the remote invoice and return the provider's immutable record ID.
     *
     * Implementations must use the supplied stable idempotency key when the provider
     * supports it and must never log access or refresh tokens.
     *
     * @param array<string, mixed> $invoice
     */
    public function upsertInvoice(
        AccountingConnection $connection,
        array $invoice,
        string $idempotencyKey,
    ): string;
}
