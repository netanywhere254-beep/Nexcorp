<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NexbookCreditNoteLine extends Model
{
    protected $table = 'nexbook_credit_note_lines';

    protected $fillable = [
        'credit_note_id', 'invoice_line_id', 'revenue_account_id', 'description', 'amount',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(NexbookCreditNote::class, 'credit_note_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(NexbookInvoiceLine::class, 'invoice_line_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(NexispAccount::class, 'revenue_account_id');
    }
}
