<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ProjectClaim extends Model
{
    protected $connection = 'tenant';
    protected $table = 'project_claims';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'percent_to_date' => 'decimal:3',
            'gross_to_date' => 'decimal:2',
            'gross' => 'decimal:2',
            'retention' => 'decimal:2',
            'advance' => 'decimal:2',
            'net' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
