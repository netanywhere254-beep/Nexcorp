<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GovernanceRequest extends Model
{
    protected $fillable = [
        'public_id', 'requested_by', 'subject_user_id', 'requested_authority',
        'scope_type', 'scope_key', 'status', 'business_reason', 'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(GovernanceApproval::class);
    }
}
