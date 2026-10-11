<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('purchase_order_id');
            $table->bigInteger('item_id')->nullable();
            $table->bigInteger('ledger_id');
            $table->string('description', 255);
            $table->decimal('qty', 15, 2)->default(1.00);
            $table->decimal('unit_price', 25, 2)->default(0.00);
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->integer('sort_order')->default(0);
            $table->index(['ledger_id'], 'ledger_id');
            $table->index(['purchase_order_id'], 'purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
    }
};
