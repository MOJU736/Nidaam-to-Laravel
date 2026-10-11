<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class BillingSetting extends Model
{
    protected $connection = 'master';
    protected $table = 'billing_settings';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
            'hourly_rate' => 'decimal:2',
            'maint_seeded' => 'boolean',
        ];
    }
}
