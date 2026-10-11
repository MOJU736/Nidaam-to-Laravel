<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PayPaymentLine extends Model
{
    protected $connection = 'tenant';
    protected $table = 'pay_payment_lines';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }
}
