<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class LoginHistory extends Model
{
    protected $connection = 'master';
    protected $table = 'login_history';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'new_device' => 'boolean',
        ];
    }
}
