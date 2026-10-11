<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('bank_rec_items', function (Blueprint $table) {
            $table->integer('rec_id')->unsigned();
            $table->bigInteger('entryitem_id');
            $table->primary(['rec_id', 'entryitem_id']);
            $table->index(['entryitem_id'], 'entryitem');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_rec_items');
    }
};
