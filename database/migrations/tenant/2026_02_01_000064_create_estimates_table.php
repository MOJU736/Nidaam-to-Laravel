<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('project_id');
            $table->integer('number')->default(1);
            $table->string('status', 10)->default('draft');
            $table->date('date');
            $table->string('title', 255)->nullable();
            $table->decimal('tax_percent', 7, 3)->default(0.000);
            $table->string('tax_name', 100)->nullable();
            $table->decimal('margin_percent', 6, 2)->default(0.00);
            $table->decimal('subtotal', 25, 2)->default(0.00);
            $table->decimal('tax_total', 25, 2)->default(0.00);
            $table->decimal('total', 25, 2)->default(0.00);
            $table->string('notes', 2000)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('approved_by', 150)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->index(['project_id'], 'project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimates');
    }
};
