<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingAccountMapping extends Model
{
    protected $fillable = [
        'organization_id', 'accounting_connection_id', 'mapping_type',
        'local_code', 'external_account_id', 'external_account_name', 'enabled',
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(AccountingConnection::class, 'accounting_connection_id');
    }
}
