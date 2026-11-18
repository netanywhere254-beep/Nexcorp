<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupStaffOtpChallenge extends Model
{
    protected $fillable = [
        'user_id', 'approved_phone_id', 'purpose', 'code_hash', 'attempts',
        'expires_at', 'verified_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function approvedPhone(): BelongsTo
    {
        return $this->belongsTo(GroupStaffApprovedPhone::class, 'approved_phone_id');
    }
}
