<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class UserDevice extends Model
{
    protected $connection = 'master';
    protected $table = 'user_devices';
    public $timestamps = false;
    protected $guarded = [];
}
