<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $connection = 'tenant';
    protected $table = 'vendors';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function ledger()
    {
        return $this->belongsTo(Ledger::class, 'ledger_id');
    }

    public function expenseLedger()
    {
        return $this->belongsTo(Ledger::class, 'expense_ledger_id');
    }

    public function bills()
    {
        return $this->hasMany(Bill::class, 'vendor_id');
    }
}
