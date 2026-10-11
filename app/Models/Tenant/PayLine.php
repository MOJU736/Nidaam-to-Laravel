<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PayLine extends Model
{
    protected $connection = 'tenant';
    protected $table = 'pay_lines';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'allowance' => 'decimal:2',
            'overtime' => 'decimal:2',
            'bonus' => 'decimal:2',
            'absent_days' => 'decimal:2',
            'absent_amount' => 'decimal:2',
            'repay' => 'decimal:2',
            'gross' => 'decimal:2',
            'net' => 'decimal:2',
            'paid' => 'decimal:2',
        ];
    }
}
