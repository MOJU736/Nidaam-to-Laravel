<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PayEmployee extends Model
{
    protected $connection = 'tenant';
    protected $table = 'pay_employees';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'start_date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
