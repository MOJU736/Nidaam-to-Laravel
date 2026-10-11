<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class BillItem extends Model
{
    protected $connection = 'tenant';
    protected $table = 'bill_items';
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

    public function bill()
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function ledger()
    {
        return $this->belongsTo(Ledger::class, 'ledger_id');
    }
}
