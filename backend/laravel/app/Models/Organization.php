<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'type',
        'status',
        'country_code',
        'timezone',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(OrganizationMember::class);
    }

    public function nexispServiceAreas(): HasMany
    {
        return $this->hasMany(NexispServiceArea::class);
    }

    public function nexispCustomers(): HasMany
    {
        return $this->hasMany(NexispCustomer::class);
    }

    public function nexispBandwidthPlans(): HasMany
    {
        return $this->hasMany(BandwidthPlan::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_members')
            ->withPivot(['role', 'status', 'joined_at'])
            ->withTimestamps();
    }
}
