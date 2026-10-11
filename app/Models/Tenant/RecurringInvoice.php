<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class RecurringInvoice extends Model
{
    protected $connection = 'tenant';
    protected $table = 'recurring_invoices';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'next_date' => 'date',
            'end_date' => 'date',
            'tax_percent' => 'decimal:3',
            'tax_total' => 'decimal:2',
            'discount_value' => 'decimal:4',
            'last_run_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
