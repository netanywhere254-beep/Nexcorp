<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;

class NexbookInvoiceLine extends Model
{
    protected $table = 'nexbook_invoice_lines';

    protected $fillable = [
        'invoice_id', 'revenue_account_id', 'description',
        'quantity', 'unit_price', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(NexbookInvoice::class, 'invoice_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(NexispAccount::class, 'revenue_account_id');
    }
}
