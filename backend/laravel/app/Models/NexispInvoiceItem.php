<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NexispInvoiceItem extends Model
{
    protected $table = 'nexisp_invoice_items';

    protected $fillable = [
        'organization_id', 'invoice_id', 'description', 'quantity', 'unit_price',
        'tax_rate_percent', 'line_subtotal', 'tax_amount', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'tax_rate_percent' => 'decimal:4',
            'line_subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(NexispInvoice::class, 'invoice_id');
    }
}
