<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('side', 2);
            $table->string('pay_type', 6)->default('pay');
            $table->bigInteger('payment_id');
            $table->bigInteger('doc_id');
            $table->decimal('amount', 25, 2)->default(0.00);
            $table->string('source', 10)->default('manual');
            $table->dateTime('created_at')->nullable();
            $table->index(['side', 'doc_id'], 'side_doc');
            $table->unique(['side', 'pay_type', 'payment_id', 'doc_id'], 'side_type_payment_doc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
