<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('project_budgets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('project_id');
            $table->string('cost_type', 20);
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->unique(['project_id', 'cost_type'], 'project_cost_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_budgets');
    }
};
