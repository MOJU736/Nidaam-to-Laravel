<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('recurring_invoice_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('recurring_id');
            $table->bigInteger('item_id')->nullable();
            $table->string('description', 255);
            $table->decimal('qty', 15, 2)->default(1.00);
            $table->decimal('unit_price', 25, 2)->default(0.00);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 25, 4)->nullable();
            $table->integer('sort_order')->default(0);
            $table->index(['recurring_id'], 'recurring_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_invoice_items');
    }
};
