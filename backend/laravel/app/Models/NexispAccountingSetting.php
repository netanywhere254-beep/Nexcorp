<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NexispAccountingSetting extends Model
{
    protected $table = 'nexisp_accounting_settings';

    protected $fillable = [
        'organization_id', 'operating_mode', 'base_currency', 'fiscal_year_start',
        'invoice_prefix', 'receipt_prefix', 'expense_prefix', 'native_ledger_enabled',
        'expenses_enabled', 'tax_enabled', 'bank_reconciliation_enabled',
        'budgets_enabled', 'recurring_invoices_enabled', 'external_sync_enabled',
        'feature_preferences',
    ];

    protected function casts(): array
    {
        return [
            'native_ledger_enabled' => 'boolean',
            'expenses_enabled' => 'boolean',
            'tax_enabled' => 'boolean',
            'bank_reconciliation_enabled' => 'boolean',
            'budgets_enabled' => 'boolean',
            'recurring_invoices_enabled' => 'boolean',
            'external_sync_enabled' => 'boolean',
            'feature_preferences' => 'array',
        ];
    }
}
