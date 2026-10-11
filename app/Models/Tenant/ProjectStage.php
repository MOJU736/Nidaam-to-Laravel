<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ProjectStage extends Model
{
    protected $connection = 'tenant';
    protected $table = 'project_stages';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'weight' => 'decimal:2',
            'percent_done' => 'decimal:2',
        ];
    }
}
