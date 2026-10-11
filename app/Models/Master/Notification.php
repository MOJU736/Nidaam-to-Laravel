<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $connection = 'master';
    protected $table = 'notifications';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
