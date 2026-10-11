<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('date');
            $table->integer('level');
            $table->string('host_ip', 25);
            $table->string('user_id', 25);
            $table->string('url', 255);
            $table->string('user_agent', 300);
            $table->string('message', 255);
            $table->index(['id'], 'id');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs');
    }
};
