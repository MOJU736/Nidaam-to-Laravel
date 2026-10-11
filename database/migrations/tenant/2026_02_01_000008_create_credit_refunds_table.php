<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('credit_refunds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('side', 2);
            $table->string('src_type', 6);
            $table->bigInteger('src_id');
            $table->bigInteger('party_id');
            $table->bigInteger('ledger_id');
            $table->date('date');
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->string('method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->bigInteger('entry_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->index(['side', 'src_type', 'src_id'], 'side_src');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_refunds');
    }
};
