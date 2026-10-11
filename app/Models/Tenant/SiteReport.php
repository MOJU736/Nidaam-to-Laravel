<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SiteReport extends Model
{
    protected $connection = 'tenant';
    protected $table = 'site_reports';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'planned_pct' => 'decimal:2',
            'actual_pct' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
