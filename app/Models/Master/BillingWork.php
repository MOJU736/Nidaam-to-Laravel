<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class BillingWork extends Model
{
    protected $connection = 'master';
    protected $table = 'billing_work';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'hours' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
