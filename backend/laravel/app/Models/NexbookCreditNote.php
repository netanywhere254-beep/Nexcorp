<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NexbookCreditNote extends Model
{
    protected $table = 'nexbook_credit_notes';

    protected $fillable = [
        'organization_id', 'invoice_id', 'created_by', 'journal_entry_id',
        'credit_note_number', 'status', 'reason', 'credit_date', 'currency',
        'subtotal', 'tax_total', 'total', 'issued_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'credit_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(NexbookInvoice::class, 'invoice_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(NexispJournalEntry::class, 'journal_entry_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(NexbookCreditNoteLine::class, 'credit_note_id');
    }
}
