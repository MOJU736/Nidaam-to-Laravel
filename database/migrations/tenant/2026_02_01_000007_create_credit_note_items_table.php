<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('credit_note_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('credit_note_id');
            $table->bigInteger('item_id')->nullable();
            $table->bigInteger('source_line_id')->nullable();
            $table->bigInteger('ledger_id')->nullable();
            $table->string('description', 255);
            $table->decimal('qty', 15, 2)->default(1.00);
            $table->decimal('unit_price', 25, 2)->default(0.00);
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->boolean('restock')->default(0);
            $table->decimal('cost_amount', 25, 2)->nullable();
            $table->integer('sort_order')->default(0);
            $table->index(['credit_note_id'], 'credit_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_items');
    }
};
