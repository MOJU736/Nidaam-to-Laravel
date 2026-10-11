<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $connection = 'master';
    protected $table = 'permissions';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'group_id' => 'boolean',
            'account_settings-index' => 'boolean',
            'account_settings-main' => 'boolean',
            'account_settings-cf' => 'boolean',
            'account_settings-email' => 'boolean',
            'account_settings-printer' => 'boolean',
            'account_settings-tags' => 'boolean',
            'account_settings-entrytypes' => 'boolean',
            'account_settings-lock' => 'boolean',
            'accounts-index' => 'boolean',
            'dashboard-index' => 'boolean',
            'entries-index' => 'boolean',
            'entries-add' => 'boolean',
            'entries-edit' => 'boolean',
            'entries-delete' => 'boolean',
            'entries-view' => 'boolean',
            'groups-add' => 'boolean',
            'groups-edit' => 'boolean',
            'groups-delete' => 'boolean',
            'ledgers-add' => 'boolean',
            'ledgers-edit' => 'boolean',
            'ledgers-delete' => 'boolean',
            'reports-index' => 'boolean',
            'reports-balancesheet' => 'boolean',
            'reports-profitloss' => 'boolean',
            'reports-trialbalance' => 'boolean',
            'reports-ledgerstatement' => 'boolean',
            'reports-ledgerentries' => 'boolean',
            'reports-reconciliation' => 'boolean',
            'search-index' => 'boolean',
            'admin-log' => 'boolean',
            'customers-access' => 'boolean',
            'sales-access' => 'boolean',
            'vendors-access' => 'boolean',
            'purchases-access' => 'boolean',
            'items-access' => 'boolean',
            'commissions-access' => 'boolean',
            'customers-create' => 'boolean',
            'customers-edit' => 'boolean',
            'customers-void' => 'boolean',
            'customers-delete' => 'boolean',
            'sales-create' => 'boolean',
            'sales-edit' => 'boolean',
            'sales-void' => 'boolean',
            'sales-delete' => 'boolean',
            'vendors-create' => 'boolean',
            'vendors-edit' => 'boolean',
            'vendors-void' => 'boolean',
            'vendors-delete' => 'boolean',
            'purchases-create' => 'boolean',
            'purchases-edit' => 'boolean',
            'purchases-void' => 'boolean',
            'purchases-delete' => 'boolean',
            'items-create' => 'boolean',
            'items-edit' => 'boolean',
            'items-void' => 'boolean',
            'items-delete' => 'boolean',
            'commissions-create' => 'boolean',
            'commissions-edit' => 'boolean',
            'commissions-void' => 'boolean',
            'commissions-delete' => 'boolean',
            'payroll-access' => 'boolean',
        ];
    }
}
