<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class PaymentReminder extends Model
{
    protected $connection = 'tenant';
    protected $table = 'payment_reminders';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'balance' => 'decimal:2',
            'overdue_amount' => 'decimal:2',
        ];
    }
}
