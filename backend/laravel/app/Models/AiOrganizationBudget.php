<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiOrganizationBudget extends Model
{
    protected $fillable = [
        'organization_id',
        'monthly_request_limit',
        'requests_used',
        'max_output_tokens',
        'period_start',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'is_enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
