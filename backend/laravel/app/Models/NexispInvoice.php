<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class NexispInvoice extends Model
{
    protected $fillable = [
        'organization_id', 'customer_id', 'subscription_id', 'invoice_number',
        'status', 'amount', 'currency', 'issued_at', 'due_at', 'paid_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
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

    public function paymentTransactions(): MorphMany
    {
        return $this->morphMany(PaymentTransaction::class, 'payable');
    }
}
