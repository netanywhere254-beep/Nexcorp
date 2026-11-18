<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorizedApprovalPhone extends Model
{
    protected $fillable = [
        'user_id', 'phone_hash', 'phone_e164', 'status',
        'verified_at', 'approved_by', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'phone_e164' => 'encrypted',
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
