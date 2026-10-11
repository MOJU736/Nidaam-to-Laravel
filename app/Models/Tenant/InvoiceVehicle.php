<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class InvoiceVehicle extends Model
{
    protected $connection = 'tenant';
    protected $table = 'invoice_vehicles';
    public $timestamps = false;
    protected $guarded = [];
}
