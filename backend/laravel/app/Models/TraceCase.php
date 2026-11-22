<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TraceCase extends Model
{
    protected $fillable = [
        'public_id', 'case_reference', 'reporter_user_id', 'reporter_phone',
        'reporter_country_code', 'reporter_region_code', 'incident_country_code',
        'incident_region_code', 'incident_locality', 'case_type', 'subject_name',
        'subject_age', 'subject_description', 'last_seen_details', 'last_seen_at',
        'status', 'publication_status', 'urgent', 'authority_reported_at',
        'authority_reference', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'reporter_phone' => 'encrypted',
            'last_seen_at' => 'datetime',
            'authority_reported_at' => 'datetime',
            'urgent' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TraceCaseDocument::class);
    }
}
