<?php

namespace App\Services\Settings;

use App\Models\Master\Account;
use App\Tenancy\TenantManager;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Backups (legacy Backup_model): one company's whole database as a restorable MySQL script (gzip when available), built in PHP so no
 * mysqldump is needed. Download on demand from Settings → Backup, and nightly copies of every company (and the main database) by
 * `php artisan backups:run`, kept for 14 days under storage/app/backups (never served by the web server).
 */
class BackupService
{
    public const CHUNK = 500;

    public const KEEP_DAYS = 14;

    protected function tenant(): Connection
    {
        return DB::connection('tenant');
    }

    /**
     * Writes every table (structure and rows) of a connection to a temp file. $prefix keeps only that company's tables when several
     * share one database. Returns ['path', 'gzip', 'tables', 'rows', 'bytes'].
     */
    public function dump(Connection $db, string $label, string $prefix = ''): array
    {
        @set_time_limit(600);
        $gzip = function_exists('gzopen');
        $path = tempnam(sys_get_temp_dir(), 'nidaam_bk_') ?: throw new \RuntimeException('The server has no temporary folder to build the backup in.');
        $fh = $gzip ? @gzopen($path, 'wb6') : @fopen($path, 'wb');
        if (! $fh) {
            throw new \RuntimeException('Could not create the backup file on the server.');
        }
        $write = fn (string $text) => $gzip ? gzwrite($fh, $text) : fwrite($fh, $text);
        $pdo = $db->getPdo();

        $tables = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM) as $row) {
            if (isset($row[1]) && strtoupper($row[1]) !== 'BASE TABLE') {
                continue; // views are rebuilt from their tables
            }
            if ($prefix === '' || str_starts_with($row[0], $prefix)) {
                $tables[] = $row[0];
            }
        }
        $q = fn (string $n) => '`'.str_replace('`', '``', $n).'`';
        $write("-- Nidaam database backup\n-- Company: ".str_replace(["\r", "\n"], ' ', $label)."\n-- Database: ".$db->getDatabaseName()."\n-- Made: ".date('Y-m-d H:i:s')."\n-- Tables: ".count($tables)."\n--\n-- Restore with phpMyAdmin (Import) into an EMPTY database, or: mysql dbname < file.sql\n\n");
        $write("SET NAMES utf8;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $total = 0;
        foreach ($tables as $table) {
            $create = $pdo->query('SHOW CREATE TABLE '.$q($table))->fetch(\PDO::FETCH_NUM);
            $write("--\n-- Table $table\n--\nDROP TABLE IF EXISTS ".$q($table).";\n".$create[1].";\n\n");
            $offset = 0;
            while (true) {
                $rows = $pdo->query('SELECT * FROM '.$q($table).' LIMIT '.self::CHUNK.' OFFSET '.$offset)->fetchAll(\PDO::FETCH_ASSOC);
                if (! $rows) {
                    break;
                }
                $cols = '('.implode(', ', array_map($q, array_keys($rows[0]))).')';
                $values = array_map(fn ($r) => '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $r)).')', $rows);
                $write('INSERT INTO '.$q($table).' '.$cols." VALUES\n".implode(",\n", $values).";\n");
                $total += count($rows);
                $offset += self::CHUNK;
                if (count($rows) < self::CHUNK) {
                    break;
                }
            }
            $write("\n");
        }
        $write("SET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n");
        $gzip ? gzclose($fh) : fclose($fh);

        return ['path' => $path, 'gzip' => $gzip, 'tables' => count($tables), 'rows' => $total, 'bytes' => filesize($path)];
    }

    public function dumpCompany(string $name): array
    {
        return $this->dump($this->tenant(), $name, (string) $this->tenant()->getTablePrefix());
    }

    public function fileName(string $companyName, array $result): string
    {
        return 'nidaam-backup-'.Str::slug($companyName ?: 'company').'-'.date('Y-m-d-His').($result['gzip'] ? '.sql.gz' : '.sql');
    }

    // ---- "last backup" note -------------------------------------------------

    protected function ensureFlags(): void
    {
        if (! Schema::connection('tenant')->hasTable('app_flags')) {
            Schema::connection('tenant')->create('app_flags', function ($t) {
                $t->string('name', 64)->primary();
                $t->string('value', 255)->nullable();
            });
        }
    }

    public function record(array $result, ?string $by): void
    {
        $this->ensureFlags();
        $value = json_encode(['at' => date('Y-m-d H:i:s'), 'tables' => $result['tables'], 'rows' => $result['rows'], 'bytes' => $result['bytes'], 'by' => $by]);
        $this->tenant()->table('app_flags')->updateOrInsert(['name' => 'last_backup'], ['value' => $value]);
    }

    public function last(): ?array
    {
        $this->ensureFlags();
        $v = json_decode((string) $this->tenant()->table('app_flags')->where('name', 'last_backup')->value('value'), true);

        return is_array($v) ? $v : null;
    }

    // ---- nightly copies -----------------------------------------------------

    public function autoDir(): string
    {
        return storage_path('app/backups').DIRECTORY_SEPARATOR;
    }

    protected function store(array $result, string $base): string
    {
        $dir = $this->autoDir().date('Y-m-d').DIRECTORY_SEPARATOR;
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true)) {
            @unlink($result['path']);
            throw new \RuntimeException('Could not create the backup folder.');
        }
        $target = $dir.$base.($result['gzip'] ? '.sql.gz' : '.sql');
        if (! @rename($result['path'], $target)) {
            if (! @copy($result['path'], $target)) {
                @unlink($result['path']);
                throw new \RuntimeException('Could not save the backup file.');
            }
            @unlink($result['path']);
        }

        return $target;
    }

    /** Backs up the main database and every company; returns the status that is also saved for the Backup page. */
    public function runAll(): array
    {
        $started = date('Y-m-d H:i:s');
        $done = [];
        $errors = [];
        try {
            $r = $this->dump(DB::connection('master'), 'Main database', '');
            $this->store($r, 'main-database');
            $done[] = ['file' => 'main-database', 'bytes' => $r['bytes']];
        } catch (\Throwable $e) {
            $errors[] = 'Main database: '.$e->getMessage();
        }
        foreach (Account::orderBy('id')->get() as $a) {
            try {
                app(TenantManager::class)->activate($a);
                $r = $this->dumpCompany((string) $a->name);
                $this->store($r, 'company-'.$a->id.'-'.Str::slug((string) $a->name ?: 'company'));
                $done[] = ['file' => 'company-'.$a->id.'-'.Str::slug((string) $a->name ?: 'company'), 'bytes' => $r['bytes']];
            } catch (\Throwable $e) {
                $errors[] = ($a->name ?: 'Company '.$a->id).': '.$e->getMessage();
            }
        }
        $this->prune();
        $status = ['started' => $started, 'finished' => date('Y-m-d H:i:s'), 'done' => $done, 'errors' => $errors];
        @file_put_contents($this->autoDir().'status.json', json_encode($status));

        return $status;
    }

    public function prune(): void
    {
        $cut = date('Y-m-d', strtotime('-'.self::KEEP_DAYS.' days'));
        foreach ((array) glob($this->autoDir().'20??-??-??', GLOB_ONLYDIR) as $d) {
            if (basename($d) < $cut) {
                foreach ((array) glob($d.DIRECTORY_SEPARATOR.'*') as $f) {
                    @unlink($f);
                }
                @rmdir($d);
            }
        }
    }

    public function autoStatus(): ?array
    {
        $f = $this->autoDir().'status.json';
        $v = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;

        return is_array($v) ? $v : null;
    }

    /** This company's nightly copies, newest first: date, file, bytes. */
    public function autoFiles(int $accountId): array
    {
        $out = [];
        foreach ((array) glob($this->autoDir().'20??-??-??', GLOB_ONLYDIR) as $d) {
            foreach ((array) glob($d.DIRECTORY_SEPARATOR.'company-'.$accountId.'-*') as $f) {
                $out[] = ['date' => basename($d), 'file' => basename($f), 'bytes' => (int) @filesize($f)];
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return $out;
    }

    /** Full path of one nightly copy, only if it really is one of ours. */
    public function autoPath(string $date, string $file): ?string
    {
        if (! preg_match('/^20\d\d-\d\d-\d\d$/', $date) || ! preg_match('/^[a-z0-9\-]+\.sql(\.gz)?$/', $file)) {
            return null;
        }
        $p = $this->autoDir().$date.DIRECTORY_SEPARATOR.$file;

        return is_file($p) ? $p : null;
    }
}
