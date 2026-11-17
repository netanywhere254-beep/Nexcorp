<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernanceApproval extends Model
{
    protected $fillable = [
        'governance_request_id', 'approver_user_id', 'approval_level',
        'required_authority', 'decision', 'comment', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function governanceRequest(): BelongsTo
    {
        return $this->belongsTo(GovernanceRequest::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
