<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BandwidthPlan extends Model
{
    protected $table = 'nexisp_bandwidth_plans';

    protected $fillable = [
        'organization_id', 'code', 'name', 'speed_mbps', 'price',
        'currency', 'validity_days', 'is_active', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(NexispSubscription::class, 'bandwidth_plan_id');
    }
}
