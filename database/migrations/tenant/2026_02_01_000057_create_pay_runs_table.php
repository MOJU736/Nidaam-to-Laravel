<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('pay_runs', function (Blueprint $table) {
            $table->increments('id');
            $table->char('period', 7);
            $table->date('date');
            $table->string('status', 10)->default('draft');
            $table->decimal('gross', 25, 2)->default(0.00);
            $table->decimal('repay', 25, 2)->default(0.00);
            $table->decimal('net', 25, 2)->default(0.00);
            $table->bigInteger('entry_id')->nullable();
            $table->string('notes', 255)->nullable();
            $table->string('created_by', 150)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('created_at');
            $table->index(['period'], 'period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_runs');
    }
};
