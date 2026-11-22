<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NexispJournalEntry extends Model
{
    protected $table = 'nexisp_journal_entries';

    protected $fillable = [
        'organization_id', 'fiscal_period_id', 'created_by', 'entry_number',
        'entry_date', 'description', 'source_type', 'source_id', 'currency',
        'status', 'posted_at', 'posted_by',
    ];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'posted_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(NexispFiscalPeriod::class, 'fiscal_period_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(NexispJournalLine::class, 'journal_entry_id');
    }
}
