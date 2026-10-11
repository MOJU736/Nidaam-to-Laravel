<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class VendorPayment extends Model
{
    protected $connection = 'tenant';
    protected $table = 'vendor_payments';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
