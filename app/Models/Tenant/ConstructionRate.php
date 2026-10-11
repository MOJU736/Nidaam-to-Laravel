<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ConstructionRate extends Model
{
    protected $connection = 'tenant';
    protected $table = 'construction_rates';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'updated_at' => 'datetime',
        ];
    }
}
