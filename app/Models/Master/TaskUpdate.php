<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class TaskUpdate extends Model
{
    protected $connection = 'master';
    protected $table = 'task_updates';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
