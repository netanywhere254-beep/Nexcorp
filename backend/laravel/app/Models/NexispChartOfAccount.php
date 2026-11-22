<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NexispChartOfAccount extends Model
{
    protected $table = 'nexisp_chart_of_accounts';

    protected $fillable = ['organization_id', 'code', 'name', 'account_type', 'normal_balance', 'parent_id', 'system_account', 'active'];

    protected function casts(): array
    {
        return ['system_account' => 'boolean', 'active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(NexispJournalEntryLine::class, 'account_id');
    }
}
