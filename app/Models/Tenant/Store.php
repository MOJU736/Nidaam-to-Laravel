<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    protected $connection = 'tenant';
    protected $table = 'stores';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
