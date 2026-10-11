<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PayAdvance extends Model
{
    protected $connection = 'tenant';
    protected $table = 'pay_advances';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'monthly' => 'decimal:2',
            'repaid' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
