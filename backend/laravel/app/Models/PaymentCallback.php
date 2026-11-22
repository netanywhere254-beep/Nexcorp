<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentCallback extends Model
{
    protected $fillable = [
        'provider', 'external_id', 'payload_hash', 'payload',
        'status', 'error', 'received_at', 'processed_at',
    ];

    protected $hidden = [
        'payload',
        'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
