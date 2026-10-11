<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('billing_plans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->decimal('price_monthly', 15, 2)->default(0.00);
            $table->decimal('price_quarterly', 15, 2)->default(0.00);
            $table->decimal('price_yearly', 15, 2)->default(0.00);
            $table->bigInteger('item_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->string('kind', 12)->default('app');
            $table->integer('max_users')->default(0);
            $table->boolean('accountant_seat')->default(1);
            $table->decimal('hours_included', 6, 2)->default(0.00);
            $table->string('response_time', 60)->nullable();
            $table->string('onsite', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_plans');
    }
};
