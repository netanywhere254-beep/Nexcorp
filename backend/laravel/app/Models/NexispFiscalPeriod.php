<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NexispFiscalPeriod extends Model
{
    protected $table = 'nexisp_fiscal_periods';

    protected $fillable = ['organization_id', 'name', 'starts_on', 'ends_on', 'status'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(NexispJournalEntry::class, 'fiscal_period_id');
    }
}
