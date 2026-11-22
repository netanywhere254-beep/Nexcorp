<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NexispCustomer extends Model
{
    protected $table = 'nexisp_customers';

    protected $fillable = [
        'organization_id',
        'service_area_id',
        'user_id',
        'customer_number',
        'type',
        'status',
        'full_name',
        'email',
        'phone',
        'physical_address',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(NexispServiceArea::class, 'service_area_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(NexispSubscription::class, 'user_id', 'user_id');
    }
}
