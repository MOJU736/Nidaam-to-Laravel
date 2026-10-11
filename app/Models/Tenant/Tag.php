<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $connection = 'tenant';
    protected $table = 'tags';
    public $timestamps = false;
    protected $guarded = [];
}
