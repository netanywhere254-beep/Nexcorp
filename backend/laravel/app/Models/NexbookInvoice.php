<?php

namespace App\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;
use Illuminate\\Database\\Eloquent\\Relations\\HasMany;

class NexbookInvoice extends Model
{
    protected $table = 'nexbook_invoices';

    protected $fillable = [
        'organization_id', 'customer_user_id', 'subscription_id',
        'journal_entry_id', 'invoice_number', 'status', 'customer_name',
        'customer_email', 'customer_phone', 'issue_date', 'due_date',
        'currency', 'subtotal', 'tax_total', 'total', 'amount_paid',
        'issued_at', 'voided_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(NexispSubscription::class, 'subscription_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(NexispJournalEntry::class, 'journal_entry_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(NexbookInvoiceLine::class, 'invoice_id');
    }
}
