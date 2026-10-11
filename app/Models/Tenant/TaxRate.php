<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $connection = 'tenant';
    protected $table = 'tax_rates';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:3',
            'on_sales' => 'boolean',
            'on_purchases' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
