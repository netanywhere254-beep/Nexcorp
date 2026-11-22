<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class NexispInvoice extends Model
{
    protected $fillable = [
        'organization_id', 'customer_id', 'subscription_id', 'invoice_number',
        'status', 'amount', 'subtotal', 'tax_amount', 'currency', 'issued_at',
        'due_at', 'paid_at', 'idempotency_key', 'notes', 'document_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'document_snapshot' => 'array',
            'issued_at' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(NexispCustomer::class, 'customer_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(NexispSubscription::class, 'subscription_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(NexispInvoiceItem::class, 'invoice_id');
    }

    public function paymentTransactions(): MorphMany
    {
        return $this->morphMany(PaymentTransaction::class, 'payable');
    }
}
