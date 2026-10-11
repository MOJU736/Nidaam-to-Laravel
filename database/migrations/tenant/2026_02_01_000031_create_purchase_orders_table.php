<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('vendor_id');
            $table->bigInteger('number');
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('notes', 500)->nullable();
            $table->decimal('subtotal', 25, 2)->default(0.00);
            $table->decimal('tax_total', 25, 2)->default(0.00);
            $table->decimal('tax_percent', 6, 3)->nullable();
            $table->decimal('total', 25, 2)->default(0.00);
            $table->dateTime('created_at')->nullable();
            $table->index(['vendor_id'], 'vendor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_orders');
    }
};
