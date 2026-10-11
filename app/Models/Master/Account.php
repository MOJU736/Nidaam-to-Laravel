<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

/** A company (tenant). Each row points at that company's own database. */
class Account extends Model
{
    protected $connection = 'master';
    protected $table = 'accounts';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['db_password'];

    protected function casts(): array
    {
        return ['fy_start' => 'date', 'fy_end' => 'date', 'account_locked' => 'boolean'];
    }
}
