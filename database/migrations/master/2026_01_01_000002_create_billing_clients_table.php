<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('billing_clients', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('account_id');
            $table->integer('plan_id')->unsigned()->nullable();
            $table->string('cycle', 10)->default('monthly');
            $table->string('status', 10)->default('active');
            $table->integer('due_days')->default(7);
            $table->bigInteger('customer_id')->nullable();
            $table->bigInteger('recurring_id')->nullable();
            $table->boolean('banner_on')->default(1);
            $table->boolean('lock_on')->default(1);
            $table->string('notes', 500)->nullable();
            $table->decimal('owed', 15, 2)->default(0.00);
            $table->decimal('overdue_amount', 15, 2)->default(0.00);
            $table->date('overdue_since')->nullable();
            $table->string('overdue_ref', 40)->nullable();
            $table->integer('overdue_count')->default(0);
            $table->date('last_paid')->nullable();
            $table->date('next_invoice')->nullable();
            $table->integer('drafts')->default(0);
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->integer('maint_plan_id')->unsigned()->nullable();
            $table->unique(['account_id'], 'uniq_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_clients');
    }
};
