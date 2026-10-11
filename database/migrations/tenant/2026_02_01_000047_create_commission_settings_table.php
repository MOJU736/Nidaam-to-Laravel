<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('commission_settings', function (Blueprint $table) {
            $table->integer('id');
            $table->bigInteger('due_to_client_ledger_id')->nullable();
            $table->bigInteger('commission_income_ledger_id')->nullable();
            $table->bigInteger('tax_expense_ledger_id')->nullable();
            $table->bigInteger('tax_payable_ledger_id')->nullable();
            $table->decimal('receive_tax_percent', 5, 2)->default(0.00);
            $table->decimal('settle_tax_percent', 5, 2)->default(0.00);
            $table->string('prefix', 20)->default('COM-');
            $table->decimal('default_percent', 7, 4)->default(0.0000);
            $table->primary(['id']);
            $table->index(['tax_expense_ledger_id'], 'tax_expense_ledger_id');
            $table->index(['tax_payable_ledger_id'], 'tax_payable_ledger_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_settings');
    }
};
