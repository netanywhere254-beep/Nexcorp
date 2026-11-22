<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TraceAccessApplication extends Model
{
    protected $fillable = [
        'user_id', 'requested_role', 'country_code', 'organisation_name',
        'organisation_registration', 'credentials_summary', 'evidence_path',
        'status', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}