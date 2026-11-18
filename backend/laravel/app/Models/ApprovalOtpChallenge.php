<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalOtpChallenge extends Model
{
    protected $fillable = [
        'governance_approval_id', 'authorized_approval_phone_id',
        'approver_user_id', 'otp_hash', 'attempts', 'expires_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function governanceApproval(): BelongsTo
    {
        return $this->belongsTo(GovernanceApproval::class);
    }

    public function phone(): BelongsTo
    {
        return $this->belongsTo(AuthorizedApprovalPhone::class, 'authorized_approval_phone_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
