<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('year_closings', function (Blueprint $table) {
            $table->increments('id');
            $table->date('fy_start');
            $table->date('fy_end');
            $table->decimal('profit', 25, 2)->default(0.00);
            $table->date('prev_lock_date')->nullable();
            $table->dateTime('closed_at');
            $table->string('closed_by', 191)->default('');
            $table->dateTime('reopened_at')->nullable();
            $table->string('reopened_by', 191)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('year_closings');
    }
};
