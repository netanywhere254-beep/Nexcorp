<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NexispExpense extends Model
{
    protected $table = 'nexisp_expenses';

    protected $fillable = [
        'organization_id', 'expense_number', 'expense_account_id', 'vendor_name',
        'description', 'amount', 'currency', 'status', 'expense_date',
        'reference', 'created_by_user_id', 'journal_entry_id', 'metadata',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expense_date' => 'date', 'metadata' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(NexispChartOfAccount::class, 'expense_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(NexispJournalEntry::class, 'journal_entry_id');
    }
}
