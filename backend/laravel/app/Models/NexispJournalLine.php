<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NexispJournalLine extends Model
{
    protected $table = 'nexisp_journal_lines';

    protected $fillable = ['journal_entry_id', 'account_id', 'memo', 'debit', 'credit'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(NexispJournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(NexispAccount::class, 'account_id');
    }
}
