<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NexispTaxRate extends Model
{
    protected $table = 'nexisp_tax_rates';

    protected $fillable = ['organization_id', 'code', 'name', 'rate_percent', 'inclusive', 'active'];

    protected function casts(): array
    {
        return ['rate_percent' => 'decimal:4', 'inclusive' => 'boolean', 'active' => 'boolean'];
    }
}
