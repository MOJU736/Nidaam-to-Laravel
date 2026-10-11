<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class EntryItem extends Model
{
    protected $connection = 'tenant';
    protected $table = 'entryitems';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reconciliation_date' => 'date',
        ];
    }

    public function entry()
    {
        return $this->belongsTo(Entry::class, 'entry_id');
    }

    public function ledger()
    {
        return $this->belongsTo(Ledger::class, 'ledger_id');
    }
}
