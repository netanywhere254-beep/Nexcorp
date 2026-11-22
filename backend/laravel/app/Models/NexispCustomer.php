<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class NexispCustomer extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'customer_number', 'name',
        'phone', 'status', 'metadata',
    ];

    protected $hidden = [
        'phone_hash',
    ];

    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $customer): void {
            if (! is_string($customer->phone) || trim($customer->phone) === '') {
                throw new RuntimeException('A customer phone number is required.');
            }

            $customer->phone_hash = self::hashPhone($customer->phone);
        });
    }

    public static function hashPhone(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?? '';
        if ($normalized === '') {
            throw new RuntimeException('A valid customer phone number is required.');
        }

        $key = (string) config('nexisp.phone_hash_key');
        if ($key === '') {
            throw new RuntimeException('NEXISP_PHONE_HASH_KEY must be configured.');
        }

        return hash_hmac('sha256', $normalized, $key);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(NexispSubscription::class, 'customer_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(NexispInvoice::class, 'customer_id');
    }
}
