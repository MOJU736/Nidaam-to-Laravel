<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('entryitems', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('entry_id');
            $table->bigInteger('ledger_id');
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->char('dc', 1);
            $table->date('reconciliation_date')->nullable();
            $table->string('narration', 500);
            $table->bigInteger('project_id')->nullable();
            $table->string('cost_type', 20)->nullable();
            $table->index(['entry_id'], 'entry_id');
            $table->index(['id'], 'id');
            $table->index(['ledger_id'], 'ledger_id');
            $table->unique(['id'], 'unique_id');
            $table->index(['project_id'], 'project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entryitems');
    }
};
