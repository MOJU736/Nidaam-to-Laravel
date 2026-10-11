<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class EstimateItem extends Model
{
    protected $connection = 'tenant';
    protected $table = 'estimate_items';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }
}
