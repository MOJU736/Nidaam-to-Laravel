<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PaymentAllocation extends Model
{
    protected $connection = 'tenant';
    protected $table = 'payment_allocations';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
