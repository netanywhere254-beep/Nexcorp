<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NexispBankTransaction extends Model
{
    protected $table = 'nexisp_bank_transactions';

    protected $fillable = [
        'organization_id', 'bank_account_id', 'external_reference', 'transaction_date',
        'description', 'amount', 'currency', 'direction', 'reconciliation_status',
        'matched_journal_entry_id',
    ];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(NexispBankAccount::class, 'bank_account_id');
    }

    public function matchedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(NexispJournalEntry::class, 'matched_journal_entry_id');
    }
}
