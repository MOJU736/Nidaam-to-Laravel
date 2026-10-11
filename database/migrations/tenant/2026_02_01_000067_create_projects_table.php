<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('number');
            $table->string('name', 255);
            $table->bigInteger('customer_id');
            $table->string('site', 255)->nullable();
            $table->decimal('contract_amount', 25, 2)->default(0.00);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->decimal('retention_percent', 6, 3)->default(0.000);
            $table->decimal('advance_amount', 25, 2)->default(0.00);
            $table->string('notes', 1000)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->string('claim_method', 10)->default('percent');
            $table->bigInteger('estimate_id')->nullable();
            $table->index(['customer_id'], 'customer_id');
            $table->index(['status'], 'status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
