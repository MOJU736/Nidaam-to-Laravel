<?php

namespace App\Services\Settings;

use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Banking\BankRecService;
use App\Services\Reports\ReportService;
use App\Support\Money;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Year-end close (legacy Yearend_model). No closing entries are posted: the Balance Sheet already shows the profit of earlier financial
 * years as Retained Earnings. Closing a year therefore only has to close the books through its last day, move the financial year on by
 * twelve months (company settings and the master company list) and write it down in year_closings so it can be undone.
 */
class YearEndService
{
    public function __construct(protected ReportService $reports, protected ChartOfAccounts $chart, protected BankRecService $banking) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['settings' => $message]);
    }

    /** The history table, made on first use (DDL: call before any transaction). */
    public function ensureTable(): void
    {
        if (Schema::connection('tenant')->hasTable('year_closings')) {
            return;
        }
        Schema::connection('tenant')->create('year_closings', function ($t) {
            $t->increments('id');
            $t->date('fy_start');
            $t->date('fy_end');
            $t->decimal('profit', 25, 2)->default(0);
            $t->date('prev_lock_date')->nullable();
            $t->dateTime('closed_at');
            $t->string('closed_by', 191)->default('');
            $t->dateTime('reopened_at')->nullable();
            $t->string('reopened_by', 191)->nullable();
        });
    }

    /** "2026" for a calendar year, else "2025-26". */
    public static function yearName(string $start, string $end): string
    {
        $ys = substr($start, 0, 4);
        $ye = substr($end, 0, 4);

        return $ys === $ye ? $ys : $ys.'-'.substr($ye, 2);
    }

    public static function nextStart(string $start): string
    {
        return Carbon::parse(SettingsService::yearEnd($start))->addDay()->toDateString();
    }

    public function history()
    {
        return $this->db()->table('year_closings')->orderByDesc('id')->get();
    }

    /** The newest closing that is still in force. */
    public function lastClosed(): ?object
    {
        return $this->db()->table('year_closings')->whereNull('reopened_at')->orderByDesc('id')->first();
    }

    /** Everything the page shows about the year being closed. */
    public function status(): array
    {
        $s = Setting::first() ?? $this->fail('This company has no settings row.');
        $start = substr((string) $s->getRawOriginal('fy_start'), 0, 10);
        $storedEnd = substr((string) $s->getRawOriginal('fy_end'), 0, 10);
        $end = SettingsService::yearEnd($start);
        $nextStart = self::nextStart($start);
        $nextEnd = SettingsService::yearEnd($nextStart);
        $today = now()->toDateString();

        $unbalanced = $this->db()->table('entries')->whereBetween('date', [$start, $end])->whereColumn('dr_total', '<>', 'cr_total')->count();
        $entries = $this->db()->table('entries')->whereBetween('date', [$start, $end])->count();
        $diff = $this->chart->openingDifference();
        $opOk = Money::isZero($diff['amount']);

        $checks = [
            ['ok' => $unbalanced === 0, 'title' => 'Every journal entry balances', 'block' => $unbalanced > 0,
                'text' => $unbalanced === 0 ? 'Debits equal credits in every entry this year.' : $unbalanced.' entr'.($unbalanced === 1 ? 'y has' : 'ies have').' debits and credits that are not equal - fix them first.',
                'url' => $unbalanced ? route('entries.index', ['from' => $start, 'to' => $end]) : null],
            ['ok' => $opOk, 'title' => 'Opening balances agree', 'block' => false,
                'text' => $opOk ? 'Debit and credit opening balances are equal.' : 'Opening balances are out by '.Money::format($diff['amount']).' - check the Chart of Accounts.', 'url' => $opOk ? null : route('chart')],
        ];
        if ($bank = $this->bankCheck($end)) {
            $checks[] = $bank;
        }
        $checks[] = ['ok' => null, 'title' => 'Download a backup', 'block' => false, 'text' => 'Recommended before closing, so you have a copy of the year exactly as it was.', 'url' => route('settings.backup')];
        $blocked = collect($checks)->contains(fn ($c) => $c['block']);
        $lock = $s->getRawOriginal('lock_date');

        return [
            'start' => $start, 'end' => $end, 'stored_end' => $storedEnd, 'too_long' => $storedEnd !== $end, 'name' => self::yearName($start, $end),
            'next_start' => $nextStart, 'next_end' => $nextEnd, 'next_name' => self::yearName($nextStart, $nextEnd), 'ended' => $end < $today, 'entries' => $entries,
            'profit' => $this->reports->netIncome($start, $end), 'checks' => $checks, 'blocked' => $blocked, 'can_close' => ! $blocked && $end < $today,
            'lock_date' => $lock ? substr((string) $lock, 0, 10) : null,
        ];
    }

    /** Reminder only: has every reconciled bank/cash account been reconciled up to the last day of the year? Null when none are in use. */
    protected function bankCheck(string $end): ?array
    {
        if (! Schema::connection('tenant')->hasTable('bank_recs')) {
            return null;
        }
        $behind = [];
        $used = 0;
        foreach ($this->banking->accounts() as $b) {
            $last = $this->banking->lastDone($b->id);
            if (! $last) {
                continue;
            }
            $used++;
            if (substr((string) $last->statement_date, 0, 10) < $end) {
                $behind[] = $b->name;
            }
        }
        if (! $used) {
            return null;
        }

        return ['ok' => ! $behind, 'title' => 'Bank accounts reconciled to year end', 'block' => false,
            'text' => ! $behind ? 'Every account you reconcile is done up to the last day of the year.' : 'Not reconciled to '.Money::date($end).' yet: '.implode(', ', $behind).'. You can still close - it is a reminder.',
            'url' => $behind ? route('banking.index') : null];
    }

    protected function who(): string
    {
        $u = request()->user();

        return $u ? (trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: (string) ($u->username ?? '')) : '';
    }

    protected function saveMasterYear(string $start, string $end): void
    {
        if ($account = app(TenantManager::class)->current()) {
            DB::connection('master')->table('accounts')->where('id', $account->id)->update(['fy_start' => $start, 'fy_end' => $end]);
        }
    }

    public function close(): string
    {
        if ($this->chart->companyLocked()) {
            $this->fail('This company is locked. Unlock it under Settings → Lock company first.');
        }
        $st = $this->status();
        if ($st['blocked']) {
            $this->fail('Fix the items marked in red first.');
        }
        if (! $st['ended']) {
            $this->fail('The '.$st['name'].' financial year runs until '.Money::date($st['end']).' - it can be closed from the next day.');
        }
        $prev = $st['lock_date'];
        DB::connection('master')->transaction(fn () => $this->db()->transaction(function () use ($st, $prev) {
            $this->db()->table('year_closings')->insert(['fy_start' => $st['start'], 'fy_end' => $st['end'], 'profit' => $st['profit'], 'prev_lock_date' => $prev,
                'closed_at' => now(), 'closed_by' => substr($this->who(), 0, 191)]);
            $this->db()->table('settings')->update(['fy_start' => $st['next_start'], 'fy_end' => $st['next_end'], 'lock_date' => ($prev && $prev > $st['end']) ? $prev : $st['end']]);
            $this->saveMasterYear($st['next_start'], $st['next_end']);
        }));
        Money::forget();
        ActivityLog::add('Closed the '.$st['name'].' financial year (profit '.$st['profit'].' to Retained Earnings); the new year starts '.$st['next_start']);

        return 'The '.$st['name'].' financial year is closed. Its profit is now Retained Earnings and the '.$st['next_name'].' year has started.';
    }

    public function reopen(): string
    {
        $last = $this->lastClosed() ?? $this->fail('There is no closed year to reopen.');
        $name = self::yearName(substr((string) $last->fy_start, 0, 10), substr((string) $last->fy_end, 0, 10));
        DB::connection('master')->transaction(fn () => $this->db()->transaction(function () use ($last) {
            $this->db()->table('year_closings')->where('id', $last->id)->update(['reopened_at' => now(), 'reopened_by' => substr($this->who(), 0, 191)]);
            $this->db()->table('settings')->update(['fy_start' => $last->fy_start, 'fy_end' => $last->fy_end, 'lock_date' => $last->prev_lock_date ?: null]);
            $this->saveMasterYear(substr((string) $last->fy_start, 0, 10), substr((string) $last->fy_end, 0, 10));
        }));
        Money::forget();
        ActivityLog::add('Reopened the '.$name.' financial year');

        return 'The '.$name.' financial year is open again.';
    }
}
