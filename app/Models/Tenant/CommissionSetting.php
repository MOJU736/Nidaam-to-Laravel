<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class CommissionSetting extends Model
{
    protected $connection = 'tenant';
    protected $table = 'commission_settings';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'receive_tax_percent' => 'decimal:2',
            'settle_tax_percent' => 'decimal:2',
            'default_percent' => 'decimal:4',
        ];
    }
}
