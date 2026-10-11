<?php

namespace App\Console\Commands;

use App\Models\Master\Account;
use App\Services\Sales\RecurringService;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

/** Creates the draft invoices that have come due, for every company. Run it daily from a scheduler: php artisan recurring:run */
class RecurringRun extends Command
{
    protected $signature = 'recurring:run';

    protected $description = 'Create the draft invoices that have come due from recurring invoices, in every company';

    public function handle(TenantManager $tenants, RecurringService $recurring): int
    {
        foreach (Account::orderBy('id')->get() as $a) {
            try {
                $tenants->activate($a);
                $made = $recurring->runDue();
                $this->line(($made ? 'CREATED ' : 'ok      ').str_pad((string) $a->label, 24).($made ? " $made draft invoice(s)" : ''));
            } catch (\Throwable $e) {
                $this->error('FAILED  '.$a->label.': '.substr($e->getMessage(), 0, 120));
            }
        }

        return self::SUCCESS;
    }
}
