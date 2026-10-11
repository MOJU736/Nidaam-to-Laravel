<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CreditNote extends Model
{
    protected $connection = 'tenant';
    protected $table = 'credit_notes';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_percent' => 'decimal:3',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
