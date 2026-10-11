<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('ledger_id');
            $table->bigInteger('expense_ledger_id')->nullable();
            $table->string('name', 255);
            $table->string('contact_person', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->integer('status')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->index(['id'], 'id');
            $table->index(['ledger_id'], 'ledger_id');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
