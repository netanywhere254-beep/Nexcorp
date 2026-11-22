<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NexispBankAccount extends Model
{
    protected $table = 'nexisp_bank_accounts';

    protected $fillable = ['organization_id', 'ledger_account_id', 'name', 'provider', 'account_reference', 'currency', 'status'];

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(NexispChartOfAccount::class, 'ledger_account_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(NexispBankTransaction::class, 'bank_account_id');
    }
}
