<?php

namespace App\Console\Commands;

use App\Services\Settings\BackupService;
use Illuminate\Console\Command;

class BackupsRun extends Command
{
    protected $signature = 'backups:run';

    protected $description = 'Back up the main database and every company to storage/app/backups (the newest 14 days are kept)';

    public function handle(BackupService $backups): int
    {
        $status = $backups->runAll();
        foreach ($status['done'] as $d) {
            $this->line('ok     '.$d['file'].' ('.number_format($d['bytes'] / 1024).' KB)');
        }
        foreach ($status['errors'] as $e) {
            $this->error('failed '.$e);
        }

        return $status['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
