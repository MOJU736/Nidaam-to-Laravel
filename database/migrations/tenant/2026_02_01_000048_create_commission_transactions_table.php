<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('commission_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('number');
            $table->string('party_name', 255);
            $table->date('date');
            $table->bigInteger('received_ledger_id');
            $table->decimal('received_amount', 25, 2)->default(0.00);
            $table->bigInteger('received_entry_id')->nullable();
            $table->decimal('received_tax_amount', 25, 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->date('settle_date')->nullable();
            $table->decimal('commission_amount', 25, 2)->nullable();
            $table->decimal('remitted_amount', 25, 2)->nullable();
            $table->decimal('settled_tax_amount', 25, 2)->nullable();
            $table->bigInteger('remit_ledger_id')->nullable();
            $table->bigInteger('settled_entry_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->decimal('commission_percent', 7, 4)->nullable();
            $table->index(['received_entry_id'], 'received_entry_id');
            $table->index(['received_ledger_id'], 'received_ledger_id');
            $table->index(['remit_ledger_id'], 'remit_ledger_id');
            $table->index(['settled_entry_id'], 'settled_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_transactions');
    }
};
