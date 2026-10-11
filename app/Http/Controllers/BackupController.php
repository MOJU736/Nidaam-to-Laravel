<?php

namespace App\Http\Controllers;

use App\Services\Accounting\ActivityLog;
use App\Services\Settings\BackupService;
use App\Services\Settings\SettingsService;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Settings → Backup: download this company's database, and the nightly copies. Administrators only (a backup holds everything, payroll and passwords included). */
class BackupController extends Controller
{
    public function __construct(protected BackupService $backups, protected SettingsService $settings) {}

    protected function account()
    {
        return app(TenantManager::class)->current();
    }

    public function index()
    {
        $account = $this->account();
        $status = $this->backups->autoStatus();
        $main = null;
        foreach ($status['done'] ?? [] as $d) {
            if (str_starts_with($d['file'], 'main-database')) {
                $main = ['date' => substr($status['started'], 0, 10), 'bytes' => $d['bytes']];
            }
        }
        $files = $this->backups->autoFiles((int) $account->id);
        // the main-database file of the latest run, if it is still on disk
        $mainFile = $main ? collect(glob($this->backups->autoDir().$main['date'].DIRECTORY_SEPARATOR.'main-database*'))->map(fn ($f) => basename($f))->first() : null;

        return view('settings.backup', ['last' => $this->backups->last(), 'company' => $this->settings->settings()->name, 'status' => $status, 'files' => array_slice($files, 0, 14),
            'main' => $main && $mainFile ? $main + ['file' => $mainFile] : null]);
    }

    public function download(Request $request)
    {
        try {
            $result = $this->backups->dumpCompany((string) $this->settings->settings()->name);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['settings' => 'The backup could not be made: '.$e->getMessage()]);
        }
        $this->backups->record($result, $request->user()?->username);
        ActivityLog::add('Downloaded a database backup ('.$result['tables'].' tables, '.$result['rows'].' rows)');

        return response()->download($result['path'], $this->backups->fileName((string) $this->settings->settings()->name, $result), [
            'Content-Type' => $result['gzip'] ? 'application/gzip' : 'application/sql', 'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }

    public function auto(string $date, string $file)
    {
        $account = $this->account();
        $mine = str_starts_with($file, 'company-'.(int) $account->id.'-');
        $path = ($mine || str_starts_with($file, 'main-database')) ? $this->backups->autoPath($date, $file) : null;
        if (! $path) {
            return redirect()->route('settings.backup')->with('error', 'That backup file was not found.');
        }
        ActivityLog::add("Downloaded the automatic backup $file of $date");

        return response()->download($path, "nidaam-$date-$file", ['Cache-Control' => 'no-store']);
    }
}
