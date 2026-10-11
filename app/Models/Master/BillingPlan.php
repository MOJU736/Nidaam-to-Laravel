<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class BillingPlan extends Model
{
    protected $connection = 'master';
    protected $table = 'billing_plans';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_quarterly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'active' => 'boolean',
            'created_at' => 'datetime',
            'accountant_seat' => 'boolean',
            'hours_included' => 'decimal:2',
        ];
    }
}
