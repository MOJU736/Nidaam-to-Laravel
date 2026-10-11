<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SalesCertificate extends Model
{
    protected $connection = 'tenant';
    protected $table = 'sales_certificates';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cert_date' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
