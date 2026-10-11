<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('workers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 255);
            $table->string('phone', 50)->nullable();
            $table->string('pay_type', 10)->default('daily');
            $table->decimal('rate', 25, 2)->default(0.00);
            $table->string('trade', 100)->nullable();
            $table->bigInteger('default_project_id')->nullable();
            $table->string('status', 10)->default('active');
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
