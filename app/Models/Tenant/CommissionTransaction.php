<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CommissionTransaction extends Model
{
    protected $connection = 'tenant';
    protected $table = 'commission_transactions';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'received_amount' => 'decimal:2',
            'received_tax_amount' => 'decimal:2',
            'settle_date' => 'date',
            'commission_amount' => 'decimal:2',
            'remitted_amount' => 'decimal:2',
            'settled_tax_amount' => 'decimal:2',
            'created_at' => 'datetime',
            'commission_percent' => 'decimal:4',
        ];
    }
}
