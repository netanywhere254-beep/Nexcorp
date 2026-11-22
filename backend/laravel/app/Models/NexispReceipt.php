<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class NexispReceipt extends Model
{
    protected $table = 'nexisp_receipts';

    protected $fillable = [
        'organization_id', 'invoice_id', 'payment_transaction_id', 'receipt_number',
        'amount', 'currency', 'payment_method', 'payment_reference', 'issued_at',
        'snapshot', 'issued_by_user_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'issued_at' => 'datetime', 'snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Issued receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Issued receipts cannot be deleted. Issue a reversal or refund record instead.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(NexispInvoice::class, 'invoice_id');
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }
}
