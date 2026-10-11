<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('project_stages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('project_id');
            $table->integer('sort')->default(0);
            $table->string('name', 255);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('weight', 6, 2)->default(0.00);
            $table->decimal('percent_done', 6, 2)->default(0.00);
            $table->string('notes', 500)->nullable();
            $table->index(['project_id'], 'project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_stages');
    }
};
