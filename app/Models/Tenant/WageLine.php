<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class WageLine extends Model
{
    protected $connection = 'tenant';
    protected $table = 'wage_lines';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'days' => 'decimal:2',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }
}
