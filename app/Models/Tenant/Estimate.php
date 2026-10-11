<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Estimate extends Model
{
    protected $connection = 'tenant';
    protected $table = 'estimates';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'tax_percent' => 'decimal:3',
            'margin_percent' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
