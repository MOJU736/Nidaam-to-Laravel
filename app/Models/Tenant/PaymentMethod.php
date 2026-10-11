<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $connection = 'tenant';
    protected $table = 'payment_methods';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
