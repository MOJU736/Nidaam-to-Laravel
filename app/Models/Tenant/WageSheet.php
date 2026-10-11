<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class WageSheet extends Model
{
    protected $connection = 'tenant';
    protected $table = 'wage_sheets';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date_from' => 'date',
            'date_to' => 'date',
            'date' => 'date',
            'total' => 'decimal:2',
            'paid_date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
