<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    protected $connection = 'tenant';
    protected $table = 'logs';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
        ];
    }
}
