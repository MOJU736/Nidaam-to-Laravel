<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class BillingClient extends Model
{
    protected $connection = 'master';
    protected $table = 'billing_clients';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'banner_on' => 'boolean',
            'lock_on' => 'boolean',
            'owed' => 'decimal:2',
            'overdue_amount' => 'decimal:2',
            'overdue_since' => 'date',
            'last_paid' => 'date',
            'next_invoice' => 'date',
            'checked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
