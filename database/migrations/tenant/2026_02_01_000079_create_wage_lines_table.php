<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('wage_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('sheet_id');
            $table->bigInteger('worker_id');
            $table->bigInteger('project_id')->nullable();
            $table->decimal('days', 7, 2)->nullable();
            $table->decimal('rate', 25, 2)->default(0.00);
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->string('note', 255)->nullable();
            $table->index(['sheet_id'], 'sheet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wage_lines');
    }
};
