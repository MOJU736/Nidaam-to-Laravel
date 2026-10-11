<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('store_stock', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('store_id');
            $table->bigInteger('item_id');
            $table->decimal('qty', 15, 2)->default(0.00);
            $table->unique(['store_id', 'item_id'], 'store_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_stock');
    }
};
