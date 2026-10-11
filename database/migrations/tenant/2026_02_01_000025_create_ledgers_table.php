<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('ledgers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('group_id');
            $table->string('name', 255);
            $table->string('code', 255)->nullable();
            $table->decimal('op_balance', 25, 2)->default(0.00);
            $table->char('op_balance_dc', 1);
            $table->integer('type')->default(0);
            $table->integer('reconciliation')->default(0);
            $table->string('notes', 500);
            $table->boolean('is_contra')->default(0);
            $table->bigInteger('parent_id')->nullable();
            $table->unique(['code'], 'code');
            $table->index(['group_id'], 'group_id');
            $table->index(['id'], 'id');
            $table->index(['parent_id'], 'parent_id');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledgers');
    }
};
