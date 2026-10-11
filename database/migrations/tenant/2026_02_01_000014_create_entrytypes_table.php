<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('entrytypes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('label', 255);
            $table->string('name', 255);
            $table->string('description', 255);
            $table->integer('base_type')->default(0);
            $table->integer('numbering')->default(1);
            $table->string('prefix', 255);
            $table->string('suffix', 255);
            $table->integer('zero_padding')->default(0);
            $table->integer('restriction_bankcash')->default(1);
            $table->index(['id'], 'id');
            $table->unique(['label'], 'label');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entrytypes');
    }
};
