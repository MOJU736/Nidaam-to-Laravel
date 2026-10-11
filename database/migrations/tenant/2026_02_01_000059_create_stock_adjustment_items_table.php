<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('adjustment_id');
            $table->bigInteger('item_id');
            $table->decimal('qty_before', 15, 2)->default(0.00);
            $table->decimal('qty_after', 15, 2)->default(0.00);
            $table->decimal('qty_change', 15, 2)->default(0.00);
            $table->decimal('unit_cost', 25, 2)->default(0.00);
            $table->decimal('value', 25, 2)->default(0.00);
            $table->integer('sort_order')->default(0);
            $table->index(['adjustment_id'], 'adjustment_id');
            $table->index(['item_id'], 'item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_items');
    }
};
