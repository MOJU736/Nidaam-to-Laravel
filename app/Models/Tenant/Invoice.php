<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $connection = 'tenant';
    protected $table = 'invoices';
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

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    public function entry()
    {
        return $this->belongsTo(Entry::class, 'entry_id');
    }
}
