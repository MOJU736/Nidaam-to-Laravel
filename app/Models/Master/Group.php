<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $connection = 'master';
    protected $table = 'groups';
    public $timestamps = false;
    protected $guarded = [];
}
