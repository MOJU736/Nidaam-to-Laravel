<?php

namespace App\Console\Commands;

use App\Models\Master\Account;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TenantsCheck extends Command
{
    protected $signature = 'tenants:check';
    protected $description = 'Connect to every company database listed in master accounts and count its tables';

    public function handle(TenantManager $tenants): int
    {
        $rows = [];
        foreach (Account::orderBy('id')->get() as $a) {
            try {
                $tenants->activate($a);
                $c = DB::connection('tenant');
                $rows[] = [$a->id, $a->label, $c->getDatabaseName(), count($c->select('show tables')), 'ok'];
            } catch (\Throwable $e) {
                $rows[] = [$a->id, $a->label, $a->db_database, '-', substr($e->getMessage(), 0, 60)];
            }
        }
        $this->table(['id', 'label', 'database', 'tables', 'status'], $rows);

        return self::SUCCESS;
    }
}