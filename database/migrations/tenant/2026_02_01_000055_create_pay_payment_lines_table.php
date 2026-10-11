<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('pay_payment_lines', function (Blueprint $table) {
            $table->integer('payment_id')->unsigned();
            $table->integer('line_id')->unsigned();
            $table->decimal('amount', 25, 2);
            $table->primary(['payment_id', 'line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_payment_lines');
    }
};
