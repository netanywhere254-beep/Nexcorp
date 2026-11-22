<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NexispJournalEntryLine extends Model
{
    protected $table = 'nexisp_journal_entry_lines';

    protected $fillable = ['organization_id', 'journal_entry_id', 'account_id', 'description', 'debit', 'credit'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(NexispJournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(NexispChartOfAccount::class, 'account_id');
    }
}
