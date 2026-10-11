<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class QuotationVehicle extends Model
{
    protected $connection = 'tenant';
    protected $table = 'quotation_vehicles';
    public $timestamps = false;
    protected $guarded = [];
}
