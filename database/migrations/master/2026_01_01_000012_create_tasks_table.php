<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('account_id')->unsigned();
            $table->string('title', 190);
            $table->text('details')->nullable();
            $table->string('status', 20)->default('not_started');
            $table->integer('assigned_to')->unsigned();
            $table->integer('created_by')->unsigned();
            $table->date('due_date')->nullable();
            $table->string('link_url', 255)->nullable();
            $table->string('link_label', 190)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('done_at')->nullable();
            $table->index(['account_id', 'assigned_to', 'status'], 'idx_tasks_assignee');
            $table->index(['account_id', 'created_by', 'status'], 'idx_tasks_creator');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
