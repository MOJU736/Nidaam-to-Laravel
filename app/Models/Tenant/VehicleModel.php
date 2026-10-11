<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class VehicleModel extends Model
{
    protected $connection = 'tenant';
    protected $table = 'vehicle_models';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
