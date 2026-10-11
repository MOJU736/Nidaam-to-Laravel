<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ProjectAdvance extends Model
{
    protected $connection = 'tenant';
    protected $table = 'project_advances';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
