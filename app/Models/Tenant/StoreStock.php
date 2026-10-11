<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class StoreStock extends Model
{
    protected $connection = 'tenant';
    protected $table = 'store_stock';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:2',
        ];
    }
}
