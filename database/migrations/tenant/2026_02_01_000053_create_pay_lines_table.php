<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('pay_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('run_id')->unsigned();
            $table->integer('employee_id')->unsigned();
            $table->decimal('salary', 25, 2)->default(0.00);
            $table->decimal('allowance', 25, 2)->default(0.00);
            $table->decimal('overtime', 25, 2)->default(0.00);
            $table->decimal('bonus', 25, 2)->default(0.00);
            $table->decimal('absent_days', 6, 2)->default(0.00);
            $table->decimal('absent_amount', 25, 2)->default(0.00);
            $table->decimal('repay', 25, 2)->default(0.00);
            $table->decimal('gross', 25, 2)->default(0.00);
            $table->decimal('net', 25, 2)->default(0.00);
            $table->decimal('paid', 25, 2)->default(0.00);
            $table->string('note', 255)->nullable();
            $table->index(['employee_id'], 'emp');
            $table->index(['run_id'], 'run');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_lines');
    }
};
