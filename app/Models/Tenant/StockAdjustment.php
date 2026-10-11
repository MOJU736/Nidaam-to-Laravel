<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class StockAdjustment extends Model
{
    protected $connection = 'tenant';
    protected $table = 'stock_adjustments';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_value' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
