<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $connection = 'tenant';
    protected $table = 'items';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sales_price' => 'decimal:2',
            'quantity_on_hand' => 'decimal:2',
            'purchase_cost' => 'decimal:2',
            'created_at' => 'datetime',
            'track_batches' => 'boolean',
            'reorder_level' => 'decimal:3',
        ];
    }

    public function category()
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function salesLedger()
    {
        return $this->belongsTo(Ledger::class, 'sales_ledger_id');
    }

    public function expenseLedger()
    {
        return $this->belongsTo(Ledger::class, 'expense_ledger_id');
    }
}
