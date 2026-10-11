<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->dateTime('created_at')->nullable();
            $table->unique(['name'], 'name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_models');
    }
};
