<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PayRun extends Model
{
    protected $connection = 'tenant';
    protected $table = 'pay_runs';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'gross' => 'decimal:2',
            'repay' => 'decimal:2',
            'net' => 'decimal:2',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
