<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ItemBatch extends Model
{
    protected $connection = 'tenant';
    protected $table = 'item_batches';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'qty' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }
}
