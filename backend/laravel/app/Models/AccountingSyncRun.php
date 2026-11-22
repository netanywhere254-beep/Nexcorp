<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingSyncRun extends Model
{
    protected $fillable = [
        'organization_id', 'accounting_connection_id', 'started_by_user_id',
        'direction', 'status', 'items_total', 'items_succeeded', 'items_failed',
        'summary', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AccountingConnection::class, 'accounting_connection_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccountingSyncItem::class, 'accounting_sync_run_id');
    }
}
