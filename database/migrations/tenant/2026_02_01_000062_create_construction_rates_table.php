<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('construction_rates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('description', 500);
            $table->string('unit', 20)->nullable();
            $table->decimal('rate', 25, 2)->default(0.00);
            $table->string('cost_type', 20)->default('materials');
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_rates');
    }
};
