<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PurchaseSetting extends Model
{
    protected $connection = 'tenant';
    protected $table = 'purchase_settings';
    public $timestamps = false;
    protected $guarded = [];
}
