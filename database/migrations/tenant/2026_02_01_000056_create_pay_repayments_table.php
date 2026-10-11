<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('pay_repayments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('run_id')->unsigned();
            $table->integer('line_id')->unsigned();
            $table->integer('advance_id')->unsigned();
            $table->decimal('amount', 25, 2);
            $table->index(['run_id'], 'run');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_repayments');
    }
};
