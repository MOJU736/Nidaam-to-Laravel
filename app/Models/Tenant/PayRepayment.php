<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PayRepayment extends Model
{
    protected $connection = 'tenant';
    protected $table = 'pay_repayments';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }
}
