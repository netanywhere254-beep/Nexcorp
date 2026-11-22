<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class NexispSubscription extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'bandwidth_plan_id',
        'subscription_number', 'status', 'price_amount', 'currency',
        'starts_at', 'ends_at', 'activated_at', 'plan_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'plan_snapshot' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(NexispCustomer::class, 'customer_id');
    }

    public function bandwidthPlan(): BelongsTo
    {
        return $this->belongsTo(BandwidthPlan::class, 'bandwidth_plan_id');
    }

    public function paymentTransactions(): MorphMany
    {
        return $this->morphMany(PaymentTransaction::class, 'payable');
    }
}
