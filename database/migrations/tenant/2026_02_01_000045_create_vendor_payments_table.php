<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('vendor_id');
            $table->bigInteger('ledger_id');
            $table->bigInteger('entry_id')->nullable();
            $table->bigInteger('posted_ledger_id')->nullable();
            $table->bigInteger('number');
            $table->date('date');
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->string('method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('status', 20)->default('paid');
            $table->dateTime('created_at')->nullable();
            $table->index(['entry_id'], 'entry_id');
            $table->index(['id'], 'id');
            $table->index(['ledger_id'], 'ledger_id');
            $table->unique(['id'], 'unique_id');
            $table->index(['vendor_id'], 'vendor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payments');
    }
};
