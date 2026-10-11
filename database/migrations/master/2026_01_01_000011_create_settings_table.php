<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('sitename', 11)->nullable();
            $table->string('drcr_toby', 4)->nullable();
            $table->string('date_format', 25)->nullable();
            $table->boolean('entry_form')->nullable();
            $table->boolean('enable_logging')->nullable();
            $table->tinyInteger('row_count')->nullable();
            $table->tinyInteger('user_registration')->nullable();
            $table->tinyInteger('admin_verification')->nullable();
            $table->tinyInteger('email_verification')->nullable();
            $table->string('email_protocol', 4)->nullable();
            $table->string('smtp_host', 255)->nullable();
            $table->smallInteger('smtp_port')->nullable();
            $table->tinyInteger('smtp_tls')->nullable();
            $table->string('smtp_username', 255)->nullable();
            $table->string('smtp_password', 255)->nullable();
            $table->string('email_from', 255)->nullable();
            $table->decimal('version', 2, 1)->nullable();
            $table->string('language', 11)->nullable();
            $table->boolean('dark_mode')->default(0);
            $table->boolean('force_https')->default(0);
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
