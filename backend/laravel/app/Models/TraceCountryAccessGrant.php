<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TraceCountryAccessGrant extends Model
{
    protected $fillable = [
        'user_id', 'source_application_id', 'access_role', 'country_code',
        'region_code', 'permissions', 'status', 'granted_by', 'expires_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(TraceAccessApplication::class, 'source_application_id');
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
