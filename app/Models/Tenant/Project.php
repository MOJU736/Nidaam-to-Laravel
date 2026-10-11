<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $connection = 'tenant';
    protected $table = 'projects';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'contract_amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'retention_percent' => 'decimal:3',
            'advance_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
