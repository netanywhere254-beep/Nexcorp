<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineerSession extends Model
{
    protected $fillable = [
        'public_id',
        'organization_id',
        'actor_user_id',
        'token_hash',
        'started_at',
        'last_activity_at',
        'idle_expires_at',
        'absolute_expires_at',
        'revoked_at',
        'revocation_reason',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'idle_expires_at' => 'datetime',
            'absolute_expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
