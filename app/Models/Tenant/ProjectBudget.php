<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ProjectBudget extends Model
{
    protected $connection = 'tenant';
    protected $table = 'project_budgets';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }
}
