<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Ledger extends Model
{
    protected $connection = 'tenant';
    protected $table = 'ledgers';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'op_balance' => 'decimal:2',
            'is_contra' => 'boolean',
        ];
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function entryItems()
    {
        return $this->hasMany(EntryItem::class, 'ledger_id');
    }

    public function customer()
    {
        return $this->hasOne(Customer::class, 'ledger_id');
    }

    public function vendor()
    {
        return $this->hasOne(Vendor::class, 'ledger_id');
    }
}
