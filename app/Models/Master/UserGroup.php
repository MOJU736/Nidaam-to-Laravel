<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class UserGroup extends Model
{
    protected $connection = 'master';
    protected $table = 'users_groups';
    public $timestamps = false;
    protected $guarded = [];
}
