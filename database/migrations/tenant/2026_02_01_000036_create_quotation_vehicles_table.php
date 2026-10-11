<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('quotation_vehicles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('quotation_id');
            $table->bigInteger('vehicle_id');
            $table->integer('sort_order')->default(0);
            $table->index(['quotation_id'], 'quotation_id');
            $table->index(['vehicle_id'], 'vehicle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_vehicles');
    }
};
