<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupStaffApprovedPhone extends Model
{
    protected $fillable = [
        'user_id', 'phone_e164', 'approved_by', 'approval_method',
        'approved_at', 'status', 'revoked_at',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
