<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ConstructionUnit extends Model
{
    protected $connection = 'tenant';
    protected $table = 'construction_units';
    public $timestamps = false;
    protected $guarded = [];
}
