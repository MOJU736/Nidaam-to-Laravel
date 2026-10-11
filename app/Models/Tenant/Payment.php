<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $connection = 'tenant';
    protected $table = 'payments';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'wht_rate' => 'decimal:4',
            'wht_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
