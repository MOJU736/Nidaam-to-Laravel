<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('estimate_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('estimate_id');
            $table->bigInteger('section_id');
            $table->string('description', 500);
            $table->string('unit', 20)->nullable();
            $table->decimal('qty', 15, 3)->default(0.000);
            $table->decimal('rate', 25, 2)->default(0.00);
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->string('cost_type', 20)->default('materials');
            $table->integer('sort')->default(0);
            $table->index(['estimate_id'], 'estimate_id');
            $table->index(['section_id'], 'section_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_items');
    }
};
