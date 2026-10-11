<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('item_vehicle_model_prices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('item_id');
            $table->bigInteger('vehicle_model_id');
            $table->decimal('unit_price', 25, 2)->default(0.00);
            $table->unique(['item_id', 'vehicle_model_id'], 'item_vehicle_model');
            $table->index(['vehicle_model_id'], 'vehicle_model_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_vehicle_model_prices');
    }
};
