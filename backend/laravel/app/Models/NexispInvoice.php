<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class NexispInvoice extends Model
{
    protected $fillable = [
        'organization_id', 'customer_id', 'subscription_id', 'invoice_number',
        'status', 'amount', 'subtotal', 'tax_amount', 'currency', 'issued_at', 'due_at', 'paid_at', 'idempotency_key', 'notes', 'document_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',\n            'subtotal' => 'decimal:2',\n            'tax_amount' => 'decimal:2',\n            'document_snapshot' => 'array',
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

    public function items(): \\Illuminate\\Database\\Eloquent\\Relations\\HasMany\n    {\n        return $this->hasMany(NexispInvoiceItem::class, 'invoice_id');\n    }\n\n    public function paymentTransactions(): MorphMany
    {
        return $this->morphMany(PaymentTransaction::class, 'payable');
    }
}
