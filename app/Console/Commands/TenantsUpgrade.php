<?php

namespace App\Console\Commands;

use App\Models\Master\Account;
use App\Services\Settings\TenantUpgrade;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TenantsUpgrade extends Command
{
    protected $signature = 'tenants:upgrade {account? : the company id; leave out for every company} {--apply : really change the database (without it, only shows what is missing)}';

    protected $description = 'Add the tables and columns an older company database is missing (never drops or changes anything that exists)';

    public function handle(TenantManager $tenants, TenantUpgrade $upgrade): int
    {
        $accounts = $this->argument('account') ? Account::whereKey((int) $this->argument('account'))->get() : Account::orderBy('id')->get();
        if ($accounts->isEmpty()) {
            $this->error('No such company.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        if ($apply) {
            $this->warn('Changing company databases. Make sure you have a fresh backup (php artisan backups:run).');
        }

        foreach ($accounts as $a) {
            try {
                $tenants->activate($a);
                $db = DB::connection('tenant');
                $plan = $upgrade->plan($db);
                $label = "#{$a->id} {$a->label} ({$db->getDatabaseName()}".($db->getTablePrefix() !== '' ? ', prefix '.$db->getTablePrefix() : '').')';
                if (! $plan) {
                    $this->info("$label: up to date.");
                    continue;
                }
                $creates = array_filter($plan, fn ($s) => $s['action'] === 'create');
                $adds = array_filter($plan, fn ($s) => $s['action'] === 'add');
                $this->line("$label: ".count($creates).' table(s) to create, '.array_sum(array_map(fn ($s) => count($s['columns']), $adds)).' column(s) to add.');
                foreach ($adds as $s) {
                    $this->line("    {$s['table']}: add ".implode(', ', $s['columns']));
                }
                if ($this->output->isVerbose()) {
                    $this->line('    create: '.implode(', ', array_map(fn ($s) => $s['table'], $creates)));
                }
                if ($apply) {
                    [$c, $n] = $upgrade->apply($db, $plan);
                    $left = $upgrade->plan($db);
                    $this->info("    done: $c table(s) created, $n column(s) added; ".($left ? count($left).' step(s) still missing!' : 'now up to date.'));
                }
            } catch (\Throwable $e) {
                $this->error("#{$a->id} {$a->label}: ".$e->getMessage());
            }
        }
        if (! $apply) {
            $this->comment('Nothing was changed. Run again with --apply to make these changes.');
        }

        return self::SUCCESS;
    }
}
