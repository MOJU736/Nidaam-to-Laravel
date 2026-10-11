<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('account_id')->unsigned();
            $table->integer('user_id')->unsigned();
            $table->integer('actor_id')->unsigned()->nullable();
            $table->integer('task_id')->unsigned()->nullable();
            $table->string('message', 255);
            $table->string('url', 255);
            $table->boolean('is_read')->default(0);
            $table->dateTime('created_at');
            $table->index(['task_id'], 'idx_notifications_task');
            $table->index(['user_id', 'is_read', 'account_id'], 'idx_notifications_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
