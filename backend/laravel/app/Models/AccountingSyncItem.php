<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingSyncItem extends Model
{
    protected $fillable = [
        'organization_id', 'accounting_connection_id', 'accounting_sync_run_id',
        'local_type', 'local_id', 'operation', 'idempotency_key', 'payload_hash',
        'external_id', 'status', 'attempts', 'last_error', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AccountingConnection::class, 'accounting_connection_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AccountingSyncRun::class, 'accounting_sync_run_id');
    }
}
