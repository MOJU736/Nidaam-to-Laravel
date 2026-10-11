<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class StockMove extends Model
{
    protected $connection = 'tenant';
    protected $table = 'stock_moves';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'qty' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
