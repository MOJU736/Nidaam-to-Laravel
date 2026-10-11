<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SalesSetting extends Model
{
    protected $connection = 'tenant';
    protected $table = 'sales_settings';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'billable_expense_invoice_backfill_applied' => 'boolean',
        ];
    }
}
