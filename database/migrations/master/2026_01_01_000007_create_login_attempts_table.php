<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('label', 25);
            $table->string('ip_address', 18);
            $table->string('login', 9);
            $table->date('time');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};
