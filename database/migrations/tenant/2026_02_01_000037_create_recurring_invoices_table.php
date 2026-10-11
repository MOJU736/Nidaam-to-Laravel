<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 150);
            $table->bigInteger('customer_id');
            $table->string('frequency', 10)->default('monthly');
            $table->integer('every')->default(1);
            $table->integer('anchor_day')->default(1);
            $table->date('start_date');
            $table->date('next_date')->nullable();
            $table->date('end_date')->nullable();
            $table->integer('due_days')->nullable();
            $table->string('notes', 500)->nullable();
            $table->decimal('tax_percent', 6, 3)->nullable();
            $table->decimal('tax_total', 25, 2)->nullable();
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 25, 4)->nullable();
            $table->string('status', 10)->default('active');
            $table->integer('created_count')->default(0);
            $table->bigInteger('last_invoice_id')->nullable();
            $table->dateTime('last_run_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->index(['status', 'next_date'], 'status_next');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoices');
    }
};
