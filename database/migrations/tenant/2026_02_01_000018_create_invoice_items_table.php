<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('invoice_id');
            $table->bigInteger('item_id')->nullable();
            $table->bigInteger('billable_expense_id')->nullable();
            $table->string('description', 255);
            $table->decimal('qty', 15, 2)->default(1.00);
            $table->decimal('unit_price', 25, 2)->default(0.00);
            $table->text('price_breakdown')->nullable();
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->decimal('cogs_amount', 25, 2)->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 25, 4)->nullable();
            $table->decimal('discount_amount', 25, 2)->default(0.00);
            $table->index(['id'], 'id');
            $table->index(['invoice_id'], 'invoice_id');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
