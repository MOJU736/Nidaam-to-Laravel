<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class RecurringInvoiceItem extends Model
{
    protected $connection = 'tenant';
    protected $table = 'recurring_invoice_items';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_value' => 'decimal:4',
        ];
    }
}
