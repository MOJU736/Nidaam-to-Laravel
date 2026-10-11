<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CustomerVehicle extends Model
{
    protected $connection = 'tenant';
    protected $table = 'customer_vehicles';
    public $timestamps = false;
    protected $guarded = [];
}
