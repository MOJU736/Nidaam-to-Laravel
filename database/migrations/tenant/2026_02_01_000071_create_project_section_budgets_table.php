<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('project_section_budgets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('project_id');
            $table->string('name', 255);
            $table->integer('sort')->default(0);
            $table->decimal('estimate_amount', 25, 2)->default(0.00);
            $table->decimal('budget', 25, 2)->default(0.00);
            $table->index(['project_id'], 'project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_section_budgets');
    }
};
