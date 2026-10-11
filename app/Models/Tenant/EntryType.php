<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class EntryType extends Model
{
    protected $connection = 'tenant';
    protected $table = 'entrytypes';
    public $timestamps = false;
    protected $guarded = [];
}
