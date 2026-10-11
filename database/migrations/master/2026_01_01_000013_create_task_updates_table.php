<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('task_updates', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('task_id')->unsigned();
            $table->integer('user_id')->unsigned();
            $table->string('kind', 20);
            $table->string('status_from', 20)->nullable();
            $table->string('status_to', 20)->nullable();
            $table->text('note')->nullable();
            $table->dateTime('created_at');
            $table->index(['task_id'], 'idx_task_updates_task');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_updates');
    }
};
