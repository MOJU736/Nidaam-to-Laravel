<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class BankRecItem extends Model
{
    protected $connection = 'tenant';
    protected $table = 'bank_rec_items';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
}
