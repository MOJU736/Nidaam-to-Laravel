<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $connection = 'master';
    protected $table = 'tasks';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }
}
