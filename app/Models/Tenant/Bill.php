<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    protected $connection = 'tenant';
    protected $table = 'bills';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'tax_percent' => 'decimal:3',
            'total' => 'decimal:2',
            'created_at' => 'datetime',
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:2',
            'discount_total' => 'decimal:2',
        ];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function items()
    {
        return $this->hasMany(BillItem::class, 'bill_id');
    }

    public function entry()
    {
        return $this->belongsTo(Entry::class, 'entry_id');
    }
}
