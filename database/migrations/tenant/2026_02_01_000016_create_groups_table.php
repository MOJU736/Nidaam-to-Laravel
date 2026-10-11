<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('parent_id')->nullable();
            $table->string('name', 255);
            $table->string('code', 255)->nullable();
            $table->integer('affects_gross')->default(0);
            $table->unique(['code'], 'code');
            $table->index(['id'], 'id');
            $table->index(['parent_id'], 'parent_id');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
