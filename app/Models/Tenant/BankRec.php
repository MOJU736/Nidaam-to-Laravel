<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class BankRec extends Model
{
    protected $connection = 'tenant';
    protected $table = 'bank_recs';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_balance' => 'decimal:2',
            'begin_balance' => 'decimal:2',
            'created_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
