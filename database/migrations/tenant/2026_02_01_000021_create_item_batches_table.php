<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('item_batches', function (Blueprint $table) {
            $table->increments('id');
            $table->bigInteger('item_id');
            $table->string('batch_no', 60);
            $table->date('expiry_date')->nullable();
            $table->decimal('qty', 25, 3)->default(0.000);
            $table->dateTime('created_at');
            $table->index(['expiry_date'], 'expiry');
            $table->index(['item_id'], 'item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_batches');
    }
};
