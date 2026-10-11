<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('payment_reminders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('customer_id');
            $table->dateTime('sent_at');
            $table->string('to_email', 255)->nullable();
            $table->decimal('balance', 25, 2)->default(0.00);
            $table->decimal('overdue_amount', 25, 2)->default(0.00);
            $table->integer('days_overdue')->default(0);
            $table->string('status', 20)->default('sent');
            $table->string('error', 500)->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->index(['customer_id'], 'customer_id');
            $table->index(['sent_at'], 'sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminders');
    }
};
