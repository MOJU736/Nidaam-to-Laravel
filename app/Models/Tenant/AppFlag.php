<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class AppFlag extends Model
{
    protected $connection = 'tenant';
    protected $table = 'app_flags';
    protected $primaryKey = 'name';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
}
