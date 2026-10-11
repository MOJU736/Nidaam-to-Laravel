<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('project_claims', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('project_id');
            $table->string('kind', 20)->default('claim');
            $table->integer('number')->default(0);
            $table->date('date');
            $table->decimal('percent_to_date', 7, 3)->nullable();
            $table->decimal('gross_to_date', 25, 2)->default(0.00);
            $table->decimal('gross', 25, 2)->default(0.00);
            $table->decimal('retention', 25, 2)->default(0.00);
            $table->decimal('advance', 25, 2)->default(0.00);
            $table->decimal('net', 25, 2)->default(0.00);
            $table->bigInteger('invoice_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->index(['invoice_id'], 'invoice_id');
            $table->index(['project_id'], 'project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_claims');
    }
};
