<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NexoraCase extends Model
{
    protected $fillable = [
        'public_id',
        'case_reference',
        'reporter_user_id',
        'case_type',
        'title',
        'description',
        'priority',
        'status',
        'visibility',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'description' => 'encrypted',
            'closed_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }
}
