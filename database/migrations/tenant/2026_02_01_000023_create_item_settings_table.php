<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('item_settings', function (Blueprint $table) {
            $table->integer('id');
            $table->bigInteger('inventory_asset_ledger_id')->nullable();
            $table->bigInteger('cogs_ledger_id')->nullable();
            $table->bigInteger('adjustment_ledger_id')->nullable();
            $table->primary(['id']);
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_settings');
    }
};
