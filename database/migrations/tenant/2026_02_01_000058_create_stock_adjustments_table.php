<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('number');
            $table->date('date');
            $table->string('reason', 30)->default('count');
            $table->string('notes', 500)->nullable();
            $table->string('status', 20)->default('posted');
            $table->decimal('total_value', 25, 2)->default(0.00);
            $table->bigInteger('entry_id')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
    }
};
