<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('label', 25);
            $table->string('name', 25);
            $table->date('fy_start');
            $table->date('fy_end');
            $table->string('db_datasource', 11);
            $table->string('db_database', 255);
            $table->string('db_host', 255);
            $table->integer('db_port');
            $table->string('db_login', 255);
            $table->string('db_password', 255);
            $table->string('db_prefix', 255)->nullable();
            $table->integer('db_persistent');
            $table->string('db_schema', 255)->nullable();
            $table->string('db_unixsocket', 11)->nullable();
            $table->string('db_settings', 11)->nullable();
            $table->string('ssl_key', 11)->nullable();
            $table->string('ssl_cert', 11)->nullable();
            $table->string('ssl_ca', 11)->nullable();
            $table->integer('account_locked');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
