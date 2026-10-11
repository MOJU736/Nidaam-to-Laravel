<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('pay_employees', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 150);
            $table->string('position', 100)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->decimal('salary', 25, 2)->default(0.00);
            $table->string('method', 10)->default('cash');
            $table->string('pay_account', 100)->nullable();
            $table->bigInteger('project_id')->nullable();
            $table->date('start_date')->nullable();
            $table->string('status', 10)->default('active');
            $table->string('notes', 500)->nullable();
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_employees');
    }
};
