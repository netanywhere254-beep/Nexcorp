<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NexbookPaymentRefundAllocation extends Model
{
    protected $table = 'nexbook_payment_refund_allocations';

    protected $fillable = ['refund_id', 'invoice_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(NexbookPaymentRefund::class, 'refund_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(NexbookInvoice::class, 'invoice_id');
    }
}
