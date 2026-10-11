<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class EstimateSection extends Model
{
    protected $connection = 'tenant';
    protected $table = 'estimate_sections';
    public $timestamps = false;
    protected $guarded = [];
}
