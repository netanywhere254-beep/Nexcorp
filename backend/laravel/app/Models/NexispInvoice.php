<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

class NexispInvoice extends Model
{
    protected $fillable = [
        'organization_id', 'customer_id', 'subscription_id', 'invoice_number',
        'status', 'amount', 'subtotal', 'tax_amount', 'currency', 'issued_at',
        'due_at', 'paid_at', 'idempotency_key', 'request_hash', 'notes', 'document_snapshot',
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

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $protectedFields = [
                'organization_id', 'customer_id', 'subscription_id', 'invoice_number',
                'amount', 'subtotal', 'tax_amount', 'currency', 'issued_at', 'due_at',
                'idempotency_key', 'request_hash', 'notes', 'document_snapshot',
            ];
            $posted = NexispJournalEntry::query()
                ->where('organization_id', $invoice->organization_id)
                ->where('source_type', self::class)
                ->where('source_id', $invoice->id)
                ->where('status', 'posted')
                ->exists();

            if ($posted && count(array_intersect($protectedFields, array_keys($invoice->getDirty()))) > 0) {
                throw new LogicException('Issued invoice financial details are immutable. Use a credit note or reversal workflow.');
            }
        });

        static::deleting(function (self $invoice): void {
            $posted = NexispJournalEntry::query()
                ->where('organization_id', $invoice->organization_id)
                ->where('source_type', self::class)
                ->where('source_id', $invoice->id)
                ->where('status', 'posted')
                ->exists();

            if ($posted) {
                throw new LogicException('Issued invoices cannot be deleted. Use a credit note or reversal workflow.');
            }
        });
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
