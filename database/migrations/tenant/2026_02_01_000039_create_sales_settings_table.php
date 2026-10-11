<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::create('sales_settings', function (Blueprint $table) {
            $table->integer('id');
            $table->bigInteger('ar_group_id')->nullable();
            $table->bigInteger('sales_ledger_id')->nullable();
            $table->bigInteger('tax_ledger_id')->nullable();
            $table->bigInteger('entrytype_id')->nullable();
            $table->string('bank_name', 255)->nullable();
            $table->string('bank_account_name', 255)->nullable();
            $table->string('bank_iban', 100)->nullable();
            $table->string('bank_account_number', 100)->nullable();
            $table->string('quotation_prefix', 20)->default('QTN-');
            $table->string('invoice_prefix', 20)->default('INV-');
            $table->string('last_csac_number', 50)->nullable();
            $table->boolean('billable_expense_invoice_backfill_applied')->default(0);
            $table->bigInteger('discount_ledger_id')->nullable();
            $table->bigInteger('bad_debt_ledger_id')->nullable();
            $table->primary(['id']);
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_settings');
    }
};
