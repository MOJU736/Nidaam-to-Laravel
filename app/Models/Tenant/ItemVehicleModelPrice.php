<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ItemVehicleModelPrice extends Model
{
    protected $connection = 'tenant';
    protected $table = 'item_vehicle_model_prices';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
        ];
    }
}
