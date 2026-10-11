<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class UserAccount extends Model
{
    protected $connection = 'master';
    protected $table = 'useraccounts';
    public $timestamps = false;
    protected $guarded = [];
}
