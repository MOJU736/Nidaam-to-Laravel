<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $connection = 'tenant';
    protected $table = 'invoice_items';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'cogs_amount' => 'decimal:2',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:2',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
