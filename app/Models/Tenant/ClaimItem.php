<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ClaimItem extends Model
{
    protected $connection = 'tenant';
    protected $table = 'claim_items';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty_to_date' => 'decimal:3',
            'rate' => 'decimal:2',
            'amount_to_date' => 'decimal:2',
        ];
    }
}
