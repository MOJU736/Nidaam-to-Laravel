<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ItemSetting extends Model
{
    protected $connection = 'tenant';
    protected $table = 'item_settings';
    public $timestamps = false;
    protected $guarded = [];
}
