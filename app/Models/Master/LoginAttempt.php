<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    protected $connection = 'master';
    protected $table = 'login_attempts';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'time' => 'date',
        ];
    }
}
