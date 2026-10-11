<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('customer_vehicles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('customer_id');
            $table->string('model', 255)->nullable();
            $table->bigInteger('vehicle_model_id')->nullable();
            $table->string('plate_number', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->index(['customer_id'], 'customer_id');
            $table->index(['vehicle_model_id'], 'vehicle_model_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_vehicles');
    }
};
