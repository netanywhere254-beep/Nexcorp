<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingConnection extends Model
{
    protected $fillable = [
        'organization_id', 'connected_by_user_id', 'provider', 'display_name',
        'external_account_id', 'status', 'access_token', 'refresh_token',
        'token_expires_at', 'granted_scopes', 'settings', 'last_synced_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'granted_scopes' => 'array',
            'settings' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(AccountingAccountMapping::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(AccountingSyncRun::class);
    }

    public function syncItems(): HasMany
    {
        return $this->hasMany(AccountingSyncItem::class);
    }
}
