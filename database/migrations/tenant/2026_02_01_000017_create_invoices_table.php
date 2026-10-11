<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('customer_id');
            $table->bigInteger('vehicle_id')->nullable();
            $table->bigInteger('quotation_id')->nullable();
            $table->bigInteger('entry_id')->nullable();
            $table->bigInteger('number');
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('notes', 500)->nullable();
            $table->decimal('subtotal', 25, 2)->default(0.00);
            $table->decimal('tax_total', 25, 2)->default(0.00);
            $table->decimal('tax_percent', 6, 3)->nullable();
            $table->decimal('total', 25, 2)->default(0.00);
            $table->dateTime('created_at')->nullable();
            $table->string('public_token', 64)->nullable();
            $table->string('cf1_value', 255)->nullable();
            $table->string('cf2_value', 255)->nullable();
            $table->string('cf3_value', 255)->nullable();
            $table->string('cf4_value', 255)->nullable();
            $table->string('cf5_value', 255)->nullable();
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 25, 4)->nullable();
            $table->decimal('discount_amount', 25, 2)->default(0.00);
            $table->decimal('discount_total', 25, 2)->default(0.00);
            $table->bigInteger('recurring_id')->nullable();
            $table->bigInteger('project_id')->nullable();
            $table->index(['customer_id'], 'customer_id');
            $table->index(['entry_id'], 'entry_id');
            $table->index(['id'], 'id');
            $table->unique(['public_token'], 'public_token');
            $table->index(['quotation_id'], 'quotation_id');
            $table->unique(['id'], 'unique_id');
            $table->index(['vehicle_id'], 'vehicle_id');
            $table->index(['project_id'], 'project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
