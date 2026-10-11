<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class YearClosing extends Model
{
    protected $connection = 'tenant';
    protected $table = 'year_closings';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fy_start' => 'date',
            'fy_end' => 'date',
            'profit' => 'decimal:2',
            'prev_lock_date' => 'date',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }
}
