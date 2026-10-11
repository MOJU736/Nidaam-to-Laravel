<?php

namespace App\Tenancy;

use App\Models\Master\Account;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class TenantManager
{
    protected ?Account $current = null;

    public function current(): ?Account
    {
        return $this->current;
    }

    /** Point the `tenant` connection at the given account's database. */
    public function activate(Account|int $account): Account
    {
        $account = $account instanceof Account ? $account : Account::findOrFail($account);

        if ($account->account_locked) {
            abort(403, 'This account is locked.');
        }

        Config::set('database.connections.tenant', array_merge(config('database.connections.tenant'), $this->credentials($account)));
        DB::purge('tenant');

        return $this->current = $account;
    }

    protected function credentials(Account $a): array
    {
        if (config('tenancy.local_remap')) {
            $name = strtolower(preg_replace('/^'.preg_quote(config('tenancy.production_prefix'), '/').'(acc_)?/i', '', $a->db_database));

            return [
                'host' => config('tenancy.local_host'),
                'port' => $a->db_port ?: 3306,
                'database' => config('tenancy.local_prefix').$name,
                'username' => config('tenancy.local_username'),
                'password' => config('tenancy.local_password'),
                'prefix' => (string) $a->db_prefix,
            ];
        }

        return [
            'host' => $a->db_host,
            'port' => $a->db_port ?: 3306,
            'database' => $a->db_database,
            'username' => $a->db_login,
            'password' => $a->db_password,
            'prefix' => (string) $a->db_prefix,
        ];
    }
}
