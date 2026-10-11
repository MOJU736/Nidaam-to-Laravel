<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('user_devices', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->char('device_hash', 64);
            $table->string('label', 80)->nullable();
            $table->integer('first_seen');
            $table->integer('last_seen');
            $table->unique(['user_id', 'device_hash'], 'uq_user_device');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};
