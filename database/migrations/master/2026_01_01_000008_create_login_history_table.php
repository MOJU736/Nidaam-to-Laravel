<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('login_history', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->string('identity', 100)->default('');
            $table->string('event', 20)->default('signin');
            $table->string('how', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('device', 80)->nullable();
            $table->boolean('new_device')->default(0);
            $table->integer('created_at');
            $table->integer('last_seen')->nullable();
            $table->integer('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();
            $table->index(['created_at'], 'idx_login_history_time');
            $table->index(['user_id', 'created_at'], 'idx_login_history_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_history');
    }
};
