<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CreditNoteItem extends Model
{
    protected $connection = 'tenant';
    protected $table = 'credit_note_items';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'restock' => 'boolean',
            'cost_amount' => 'decimal:2',
        ];
    }
}
