<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('pay_advances', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('employee_id')->unsigned();
            $table->string('kind', 10)->default('advance');
            $table->date('date');
            $table->decimal('amount', 25, 2);
            $table->decimal('monthly', 25, 2)->default(0.00);
            $table->decimal('repaid', 25, 2)->default(0.00);
            $table->bigInteger('ledger_id')->nullable();
            $table->bigInteger('entry_id')->nullable();
            $table->string('status', 10)->default('open');
            $table->string('notes', 255)->nullable();
            $table->dateTime('created_at');
            $table->index(['employee_id'], 'emp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_advances');
    }
};
