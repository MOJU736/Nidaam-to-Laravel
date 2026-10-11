<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('group_id')->default(0);
            $table->boolean('account_settings-index')->default(0);
            $table->boolean('account_settings-main')->default(0);
            $table->boolean('account_settings-cf')->default(0);
            $table->boolean('account_settings-email')->default(0);
            $table->boolean('account_settings-printer')->default(0);
            $table->boolean('account_settings-tags')->default(0);
            $table->boolean('account_settings-entrytypes')->default(0);
            $table->boolean('account_settings-lock')->default(0);
            $table->boolean('accounts-index')->default(0);
            $table->boolean('dashboard-index')->default(0);
            $table->boolean('entries-index')->default(0);
            $table->boolean('entries-add')->default(0);
            $table->boolean('entries-edit')->default(0);
            $table->boolean('entries-delete')->default(0);
            $table->boolean('entries-view')->default(0);
            $table->boolean('groups-add')->default(0);
            $table->boolean('groups-edit')->default(0);
            $table->boolean('groups-delete')->default(0);
            $table->boolean('ledgers-add')->default(0);
            $table->boolean('ledgers-edit')->default(0);
            $table->boolean('ledgers-delete')->default(0);
            $table->boolean('reports-index')->default(0);
            $table->boolean('reports-balancesheet')->default(0);
            $table->boolean('reports-profitloss')->default(0);
            $table->boolean('reports-trialbalance')->default(0);
            $table->boolean('reports-ledgerstatement')->default(0);
            $table->boolean('reports-ledgerentries')->default(0);
            $table->boolean('reports-reconciliation')->default(0);
            $table->boolean('search-index')->default(0);
            $table->boolean('admin-log')->default(0);
            $table->boolean('customers-access')->default(0);
            $table->boolean('sales-access')->default(0);
            $table->boolean('vendors-access')->default(0);
            $table->boolean('purchases-access')->default(0);
            $table->boolean('items-access')->default(0);
            $table->boolean('commissions-access')->default(0);
            $table->boolean('customers-create')->default(0);
            $table->boolean('customers-edit')->default(0);
            $table->boolean('customers-void')->default(0);
            $table->boolean('customers-delete')->default(0);
            $table->boolean('sales-create')->default(0);
            $table->boolean('sales-edit')->default(0);
            $table->boolean('sales-void')->default(0);
            $table->boolean('sales-delete')->default(0);
            $table->boolean('vendors-create')->default(0);
            $table->boolean('vendors-edit')->default(0);
            $table->boolean('vendors-void')->default(0);
            $table->boolean('vendors-delete')->default(0);
            $table->boolean('purchases-create')->default(0);
            $table->boolean('purchases-edit')->default(0);
            $table->boolean('purchases-void')->default(0);
            $table->boolean('purchases-delete')->default(0);
            $table->boolean('items-create')->default(0);
            $table->boolean('items-edit')->default(0);
            $table->boolean('items-void')->default(0);
            $table->boolean('items-delete')->default(0);
            $table->boolean('commissions-create')->default(0);
            $table->boolean('commissions-edit')->default(0);
            $table->boolean('commissions-void')->default(0);
            $table->boolean('commissions-delete')->default(0);
            $table->boolean('payroll-access')->default(0);
            $table->index(['group_id'], 'group_id');
            $table->index(['id'], 'id');
            $table->unique(['id'], 'unique_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
