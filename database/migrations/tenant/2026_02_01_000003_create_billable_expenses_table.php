<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('billable_expenses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('entry_id')->nullable();
            $table->bigInteger('bill_item_id')->nullable();
            $table->bigInteger('customer_id');
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->date('date');
            $table->bigInteger('invoice_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->unique(['bill_item_id'], 'bill_item_id');
            $table->index(['customer_id'], 'customer_id');
            $table->unique(['entry_id'], 'entry_id');
            $table->index(['invoice_id'], 'invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billable_expenses');
    }
};
