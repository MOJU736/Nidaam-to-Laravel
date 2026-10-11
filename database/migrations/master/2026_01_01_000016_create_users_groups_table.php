<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('users_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->string('user_id', 18);
            $table->string('group_id', 18);
            $table->index(['group_id'], 'group_id');
            $table->index(['id'], 'id');
            $table->unique(['id'], 'unique_id');
            $table->index(['user_id'], 'user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users_groups');
    }
};
