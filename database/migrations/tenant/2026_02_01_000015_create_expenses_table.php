<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('expense_ledger_id');
            $table->bigInteger('item_id')->nullable();
            $table->bigInteger('ledger_id');
            $table->bigInteger('entry_id')->nullable();
            $table->bigInteger('number');
            $table->date('date');
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->string('method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('status', 20)->default('paid');
            $table->dateTime('created_at')->nullable();
            $table->bigInteger('project_id')->nullable();
            $table->string('cost_type', 20)->nullable();
            $table->index(['entry_id'], 'entry_id');
            $table->index(['expense_ledger_id'], 'expense_ledger_id');
            $table->index(['ledger_id'], 'ledger_id');
            $table->index(['project_id'], 'project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
