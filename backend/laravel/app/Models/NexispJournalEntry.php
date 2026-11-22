<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NexispJournalEntry extends Model
{
    protected $table = 'nexisp_journal_entries';

    protected $fillable = [
        'organization_id', 'entry_number', 'status', 'source_type', 'source_id',
        'description', 'currency', 'entry_date', 'total_debit', 'total_credit',
        'created_by_user_id', 'posted_by_user_id', 'posted_at', 'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(NexispJournalEntryLine::class, 'journal_entry_id');
    }
}
