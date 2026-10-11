<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('batch_moves', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('batch_id')->unsigned();
            $table->bigInteger('item_id');
            $table->string('doc', 12);
            $table->bigInteger('doc_id')->nullable();
            $table->bigInteger('line_id')->nullable();
            $table->decimal('qty', 25, 3);
            $table->date('date');
            $table->dateTime('created_at');
            $table->index(['batch_id'], 'batch');
            $table->index(['doc', 'doc_id'], 'doc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_moves');
    }
};
