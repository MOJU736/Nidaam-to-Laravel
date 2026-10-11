<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class SiteReportPhoto extends Model
{
    protected $connection = 'tenant';
    protected $table = 'site_report_photos';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
