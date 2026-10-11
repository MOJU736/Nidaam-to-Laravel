<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class StockAdjustmentItem extends Model
{
    protected $connection = 'tenant';
    protected $table = 'stock_adjustment_items';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty_before' => 'decimal:2',
            'qty_after' => 'decimal:2',
            'qty_change' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'value' => 'decimal:2',
        ];
    }
}
