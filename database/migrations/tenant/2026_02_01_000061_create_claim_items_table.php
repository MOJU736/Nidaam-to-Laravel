<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('claim_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('claim_id');
            $table->bigInteger('estimate_item_id');
            $table->decimal('qty_to_date', 15, 3)->default(0.000);
            $table->decimal('rate', 25, 2)->default(0.00);
            $table->decimal('amount_to_date', 25, 2)->default(0.00);
            $table->index(['claim_id'], 'claim_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_items');
    }
};
