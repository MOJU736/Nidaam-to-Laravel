<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('bank_recs', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('ledger_id');
            $table->date('statement_date');
            $table->decimal('statement_balance', 25, 2)->default(0.00);
            $table->decimal('begin_balance', 25, 2)->default(0.00);
            $table->string('status', 10)->default('draft');
            $table->dateTime('created_at');
            $table->string('created_by', 191)->default('');
            $table->dateTime('finished_at')->nullable();
            $table->string('finished_by', 191)->nullable();
            $table->index(['ledger_id', 'status'], 'ledger_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_recs');
    }
};
