<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ProjectSectionBudget extends Model
{
    protected $connection = 'tenant';
    protected $table = 'project_section_budgets';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'estimate_amount' => 'decimal:2',
            'budget' => 'decimal:2',
        ];
    }
}
