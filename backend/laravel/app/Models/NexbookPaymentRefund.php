<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NexbookPaymentRefund extends Model
{
    protected $table = 'nexbook_payment_refunds';

    protected $fillable = [
        'organization_id', 'payment_id', 'created_by', 'journal_entry_id',
        'refund_number', 'status', 'refund_date', 'currency', 'method',
        'reference', 'reason', 'amount', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'refund_date' => 'date',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(NexbookPayment::class, 'payment_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(NexispJournalEntry::class, 'journal_entry_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(NexbookPaymentRefundAllocation::class, 'refund_id');
    }
}
