<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class BatchMove extends Model
{
    protected $connection = 'tenant';
    protected $table = 'batch_moves';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'date' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
