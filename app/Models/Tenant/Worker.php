<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Worker extends Model
{
    protected $connection = 'tenant';
    protected $table = 'workers';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
