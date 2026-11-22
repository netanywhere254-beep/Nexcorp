<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NexbookPayment extends Model
{
    protected $table = 'nexbook_payments';

    protected $fillable = [
        'organization_id', 'received_by', 'journal_entry_id', 'receipt_number',
        'status', 'payment_date', 'currency', 'method', 'reference',
        'amount', 'allocated_amount', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'allocated_amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(NexispJournalEntry::class, 'journal_entry_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(NexbookPaymentAllocation::class, 'payment_id');
    }
}
