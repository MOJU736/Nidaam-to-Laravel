<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class QuotationItem extends Model
{
    protected $connection = 'tenant';
    protected $table = 'quotation_items';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:2',
        ];
    }
}
