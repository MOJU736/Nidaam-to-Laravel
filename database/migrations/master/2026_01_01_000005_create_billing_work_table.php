<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('billing_work', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('account_id');
            $table->date('work_date');
            $table->string('kind', 20)->default('change');
            $table->decimal('hours', 6, 2)->default(0.00);
            $table->string('title', 190);
            $table->string('notes', 500)->nullable();
            $table->bigInteger('invoice_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->index(['account_id', 'work_date'], 'acc_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_work');
    }
};
