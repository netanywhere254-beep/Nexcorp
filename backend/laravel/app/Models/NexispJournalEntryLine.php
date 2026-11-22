<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class NexispJournalEntryLine extends Model
{
    protected $table = 'nexisp_journal_entry_lines';

    protected $fillable = ['organization_id', 'journal_entry_id', 'account_id', 'description', 'debit', 'credit'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $entry = NexispJournalEntry::query()->whereKey($line->journal_entry_id)->first();
            if ($entry?->status === 'posted') {
                throw new LogicException('Cannot add lines to a posted journal entry.');
            }
        });

        static::updating(function (self $line): void {
            if ($line->entry()->value('status') === 'posted') {
                throw new LogicException('Lines in a posted journal entry are immutable.');
            }
        });

        static::deleting(function (self $line): void {
            if ($line->entry()->value('status') === 'posted') {
                throw new LogicException('Lines in a posted journal entry cannot be deleted.');
            }
        });
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
