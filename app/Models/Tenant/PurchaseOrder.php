<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $connection = 'tenant';
    protected $table = 'purchase_orders';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'tax_percent' => 'decimal:3',
            'total' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
