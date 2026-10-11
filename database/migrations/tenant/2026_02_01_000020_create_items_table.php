<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('type', 20)->default('service');
            $table->bigInteger('category_id')->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('name', 255);
            $table->string('description', 500)->nullable();
            $table->decimal('sales_price', 25, 2)->default(0.00);
            $table->bigInteger('sales_ledger_id');
            $table->bigInteger('expense_ledger_id')->nullable();
            $table->decimal('quantity_on_hand', 15, 2)->default(0.00);
            $table->decimal('purchase_cost', 25, 2)->default(0.00);
            $table->integer('status')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->boolean('track_batches')->default(0);
            $table->decimal('reorder_level', 25, 3)->nullable();
            $table->index(['category_id'], 'category_id');
            $table->index(['expense_ledger_id'], 'expense_ledger_id');
            $table->index(['id'], 'id');
            $table->index(['sales_ledger_id'], 'sales_ledger_id');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
