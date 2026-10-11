<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->integer('id')->unsigned();
            $table->integer('billing_account_id')->nullable();
            $table->string('pay_note', 500)->nullable();
            $table->integer('lock_days')->default(30);
            $table->dateTime('updated_at')->nullable();
            $table->decimal('hourly_rate', 10, 2)->default(15.00);
            $table->boolean('maint_seeded')->default(0);
            $table->primary(['id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_settings');
    }
};
