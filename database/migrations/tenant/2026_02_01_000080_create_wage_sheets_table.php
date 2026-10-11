<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('wage_sheets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('number');
            $table->date('date_from');
            $table->date('date_to');
            $table->date('date');
            $table->decimal('total', 25, 2)->default(0.00);
            $table->bigInteger('paid_ledger_id')->nullable();
            $table->date('paid_date')->nullable();
            $table->bigInteger('entry_id')->nullable();
            $table->bigInteger('paid_entry_id')->nullable();
            $table->string('status', 10)->default('posted');
            $table->string('notes', 500)->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wage_sheets');
    }
};
