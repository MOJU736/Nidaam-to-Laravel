<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $connection = 'tenant';
    protected $table = 'settings';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['email_password'];

    protected function casts(): array
    {
        return [
            'fy_start' => 'date',
            'fy_end' => 'date',
            'print_paper_height' => 'decimal:3',
            'print_paper_width' => 'decimal:3',
            'print_margin_top' => 'decimal:3',
            'print_margin_bottom' => 'decimal:3',
            'print_margin_left' => 'decimal:3',
            'print_margin_right' => 'decimal:3',
            'commissions_enabled' => 'boolean',
            'lock_date' => 'date',
            'construction_enabled' => 'boolean',
            'vehicles_enabled' => 'boolean',
            'billable_expenses_enabled' => 'boolean',
            'datasharing_enabled' => 'boolean',
            'datasharing_only' => 'boolean',
        ];
    }
}
