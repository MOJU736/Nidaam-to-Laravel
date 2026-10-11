<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('ip_address', 18);
            $table->string('username', 25);
            $table->string('password', 255);
            $table->string('salt', 11);
            $table->string('email', 30);
            $table->string('activation_code', 1)->nullable();
            $table->string('forgotten_password_code', 1)->nullable();
            $table->string('forgotten_password_time', 1)->nullable();
            $table->string('remember_code', 25)->nullable();
            $table->integer('created_on')->unsigned()->nullable();
            $table->integer('last_login')->unsigned()->nullable();
            $table->boolean('active')->nullable();
            $table->string('first_name', 11)->nullable();
            $table->string('last_name', 11)->nullable();
            $table->string('company', 25)->nullable();
            $table->bigInteger('phone')->nullable();
            $table->string('image', 100)->nullable();
            $table->text('accounts')->nullable();
            $table->boolean('all_accounts')->nullable();
            $table->string('session_token', 64)->nullable();
            $table->boolean('must_change_password')->default(0);
            $table->integer('temp_password_expires')->nullable();
            $table->string('invite_code', 64)->nullable();
            $table->integer('invite_expires')->nullable();
            $table->integer('password_changed_at')->nullable();
            $table->string('theme', 5)->nullable();
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
