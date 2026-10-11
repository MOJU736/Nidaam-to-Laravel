<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $connection = 'tenant';
    protected $table = 'quotations';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'expiry_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'tax_percent' => 'decimal:3',
            'total' => 'decimal:2',
            'created_at' => 'datetime',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'discount_total' => 'decimal:2',
        ];
    }
}
