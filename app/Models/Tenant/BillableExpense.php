<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class BillableExpense extends Model
{
    protected $connection = 'tenant';
    protected $table = 'billable_expenses';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
