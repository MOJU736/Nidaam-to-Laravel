<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('pay_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('run_id')->unsigned();
            $table->integer('line_id')->unsigned()->nullable();
            $table->date('date');
            $table->decimal('amount', 25, 2);
            $table->bigInteger('ledger_id');
            $table->bigInteger('entry_id')->nullable();
            $table->dateTime('created_at');
            $table->index(['run_id'], 'run');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_payments');
    }
};
