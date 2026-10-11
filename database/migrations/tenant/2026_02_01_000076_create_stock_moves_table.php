<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('stock_moves', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('number');
            $table->string('kind', 10);
            $table->date('date');
            $table->bigInteger('item_id');
            $table->decimal('qty', 15, 2);
            $table->bigInteger('from_store_id')->default(0);
            $table->bigInteger('to_store_id')->nullable();
            $table->bigInteger('project_id')->nullable();
            $table->decimal('unit_cost', 25, 2)->default(0.00);
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->bigInteger('entry_id')->nullable();
            $table->string('status', 10)->default('posted');
            $table->string('notes', 500)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->index(['item_id'], 'item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_moves');
    }
};
