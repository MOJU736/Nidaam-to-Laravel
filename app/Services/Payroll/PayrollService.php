<?php

namespace App\Services\Payroll;

use App\Models\Tenant\EntryType;
use App\Models\Tenant\Group;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Payroll for monthly staff. Ported from the legacy Payroll_model.
 *
 * Employees have a monthly salary and a payment method. A pay run is one month: a draft with a line per active employee
 * (salary, allowance, overtime, bonus, absent days, advance / loan repayment), changed until it is right, then approved:
 *
 *   Dr Salaries                    gross of every line (one line per project when employees are tagged to projects)
 *     Cr Staff Advances and Loans  what is taken back this month
 *     Cr Salaries Payable          net pay
 *
 * Paying (everyone, or one employee): Dr Salaries Payable / Cr cash-bank. An advance or loan: Dr Staff Advances and Loans /
 * Cr cash-bank; each pay run suggests taking back the monthly repayment until it is paid off (oldest first). Voiding a run
 * removes its entry and gives the repayments back to the advances.
 *
 *   absent pay = salary / 30 x absent days      gross = salary - absent pay + allowance + overtime + bonus      net = gross - repayment
 */
class PayrollService
{
    public const METHODS = ['cash' => 'Cash', 'bank' => 'Bank', 'evc' => 'EVC Plus / mobile money'];

    /** The accounts payroll posts to: found by name first, made once otherwise (and remembered in app_flags). */
    protected const LEDGERS = [
        'salary_expense' => ['root' => 'expense', 'patterns' => ['salaries and wages', 'salaries', 'salary', 'payroll'], 'exclude' => ['payable', 'accrued', 'tax', 'benefit', 'pension'],
            'group_prefix' => '04', 'group_patterns' => ['general and admin', 'administrative', 'operating', 'expense'], 'name' => 'Salaries', 'dc' => 'D', 'notes' => 'Staff salaries from monthly pay runs'],
        'salary_payable' => ['root' => 'liability', 'patterns' => ['salaries payable', 'salary payable', 'payroll payable'], 'exclude' => ['tax'],
            'group_prefix' => '02', 'group_patterns' => ['current liab', 'other current', 'payable'], 'name' => 'Salaries Payable', 'dc' => 'C', 'notes' => 'Net pay approved in pay runs and not paid yet'],
        'staff_advances' => ['root' => 'asset', 'patterns' => ['staff advance', 'employee advance', 'salary advance', 'staff loan', 'employee loan'], 'exclude' => [],
            'group_prefix' => '01', 'group_patterns' => ['other current', 'current asset', 'receivable'], 'name' => 'Staff Advances and Loans', 'dc' => 'D', 'notes' => 'Salary advances and loans to staff, taken back from pay runs'],
        // Construction (same mechanism, same app_flags keys as the legacy app): progress claims and wage sheets post to these
        'retention' => ['root' => 'asset', 'patterns' => ['retention receivable', 'retention'], 'exclude' => ['payable'],
            'group_prefix' => '01', 'group_patterns' => ['current asset', 'receivable'], 'name' => 'Retention Receivable', 'dc' => 'D', 'notes' => 'Retention held back by construction clients until handover'],
        'wages_expense' => ['root' => 'expense', 'patterns' => ['site labour', 'site labor', 'site wages', 'wages', 'salaries', 'salary'], 'exclude' => ['payable', 'accrued'],
            'group_prefix' => '04', 'group_patterns' => ['direct cost', 'cost of sales', 'cost of goods'], 'name' => 'Site Wages', 'dc' => 'D', 'notes' => 'Wages of site workers from wage sheets'],
        'wages_payable' => ['root' => 'liability', 'patterns' => ['wages payable', 'salaries payable', 'salary payable', 'accrued wages'], 'exclude' => [],
            'group_prefix' => '02', 'group_patterns' => ['current liab', 'payable'], 'name' => 'Wages Payable', 'dc' => 'C', 'notes' => 'Wages on wage sheets not paid yet'],
        'materials_used' => ['root' => 'expense', 'patterns' => ['site materials used', 'materials used', 'construction materials', 'cost of materials'], 'exclude' => [],
            'group_prefix' => '04', 'group_patterns' => ['direct cost', 'cost of sales', 'cost of goods'], 'name' => 'Site Materials Used', 'dc' => 'D', 'notes' => 'Stock issued to construction sites'],
    ];

    /** The accounts a pay run posts to (the rest of LEDGERS belong to Construction). */
    protected const PAYROLL_KEYS = ['salary_expense', 'salary_payable', 'staff_advances'];

    public function __construct(protected ChartOfAccounts $chart, protected EntryService $entries) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    /** Older company databases have no payroll tables yet; they are made the first time Payroll is opened (never inside a transaction). */
    public function ensureTables(): void
    {
        static $done = [];
        $key = config('database.connections.tenant.database');
        if (isset($done[$key])) {
            return;
        }
        $schema = Schema::connection('tenant');
        if (! $schema->hasTable('app_flags')) {
            $schema->create('app_flags', function (Blueprint $t) {
                $t->string('name', 64);
                $t->string('value', 255)->nullable();
                $t->primary(['name']);
            });
        }
        if (! $schema->hasTable('pay_employees')) {
            $schema->create('pay_employees', function (Blueprint $t) {
                $t->increments('id');
                $t->string('name', 150);
                $t->string('position', 100)->nullable();
                $t->string('department', 100)->nullable();
                $t->string('phone', 50)->nullable();
                $t->string('email', 150)->nullable();
                $t->decimal('salary', 25, 2)->default(0);
                $t->string('method', 10)->default('cash');
                $t->string('pay_account', 100)->nullable();
                $t->bigInteger('project_id')->nullable();
                $t->date('start_date')->nullable();
                $t->string('status', 10)->default('active');
                $t->string('notes', 500)->nullable();
                $t->dateTime('created_at');
            });
        }
        if (! $schema->hasTable('pay_advances')) {
            $schema->create('pay_advances', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('employee_id');
                $t->string('kind', 10)->default('advance');
                $t->date('date');
                $t->decimal('amount', 25, 2);
                $t->decimal('monthly', 25, 2)->default(0);
                $t->decimal('repaid', 25, 2)->default(0);
                $t->bigInteger('ledger_id')->nullable();
                $t->bigInteger('entry_id')->nullable();
                $t->string('status', 10)->default('open');
                $t->string('notes', 255)->nullable();
                $t->dateTime('created_at');
                $t->index(['employee_id'], 'emp');
            });
        }
        if (! $schema->hasTable('pay_runs')) {
            $schema->create('pay_runs', function (Blueprint $t) {
                $t->increments('id');
                $t->char('period', 7);
                $t->date('date');
                $t->string('status', 10)->default('draft');
                $t->decimal('gross', 25, 2)->default(0);
                $t->decimal('repay', 25, 2)->default(0);
                $t->decimal('net', 25, 2)->default(0);
                $t->bigInteger('entry_id')->nullable();
                $t->string('notes', 255)->nullable();
                $t->string('created_by', 150)->nullable();
                $t->dateTime('approved_at')->nullable();
                $t->dateTime('created_at');
                $t->index(['period'], 'period');
            });
        }
        if (! $schema->hasTable('pay_lines')) {
            $schema->create('pay_lines', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('run_id');
                $t->unsignedInteger('employee_id');
                $t->decimal('salary', 25, 2)->default(0);
                $t->decimal('allowance', 25, 2)->default(0);
                $t->decimal('overtime', 25, 2)->default(0);
                $t->decimal('bonus', 25, 2)->default(0);
                $t->decimal('absent_days', 6, 2)->default(0);
                $t->decimal('absent_amount', 25, 2)->default(0);
                $t->decimal('repay', 25, 2)->default(0);
                $t->decimal('gross', 25, 2)->default(0);
                $t->decimal('net', 25, 2)->default(0);
                $t->decimal('paid', 25, 2)->default(0);
                $t->string('note', 255)->nullable();
                $t->index(['employee_id'], 'emp');
                $t->index(['run_id'], 'run');
            });
        }
        if (! $schema->hasTable('pay_repayments')) {
            $schema->create('pay_repayments', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('run_id');
                $t->unsignedInteger('line_id');
                $t->unsignedInteger('advance_id');
                $t->decimal('amount', 25, 2);
                $t->index(['run_id'], 'run');
            });
        }
        if (! $schema->hasTable('pay_payments')) {
            $schema->create('pay_payments', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedInteger('run_id');
                $t->unsignedInteger('line_id')->nullable();
                $t->date('date');
                $t->decimal('amount', 25, 2);
                $t->bigInteger('ledger_id');
                $t->bigInteger('entry_id')->nullable();
                $t->dateTime('created_at');
                $t->index(['run_id'], 'run');
            });
        }
        if (! $schema->hasTable('pay_payment_lines')) {
            $schema->create('pay_payment_lines', function (Blueprint $t) {
                $t->unsignedInteger('payment_id');
                $t->unsignedInteger('line_id');
                $t->decimal('amount', 25, 2);
                $t->primary(['payment_id', 'line_id']);
            });
        }
        $done[$key] = true;
    }

    // ---- small helpers ------------------------------------------------------------------

    /** "1,250.5" -> "1250.50"; '' -> '0.00'; null when it is not a number (or is below 0). */
    public function moneyIn($v): ?string
    {
        $v = str_replace([',', ' ', '$'], '', trim((string) $v));
        if ($v === '') {
            return '0.00';
        }
        if (! preg_match('/^\d+(\.\d+)?$/', $v)) {
            return null;
        }

        return Money::round($v);
    }

    public function who(): string
    {
        $u = auth()->user();

        return $u ? (trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: (string) ($u->username ?? '')) : '';
    }

    public function monthLabel(string $period): string
    {
        return date('F Y', strtotime($period.'-01'));
    }

    /** Posting and changing money is refused while the company is locked or the date falls in a closed period. */
    protected function guard(?string $date): void
    {
        if ($this->chart->companyLocked()) {
            $this->fail('This company is locked, so nothing can be changed. An administrator can unlock it under Settings.');
        }
        if ($date && ($err = $this->entries->closedPeriodError(substr($date, 0, 10)))) {
            $this->fail($err);
        }
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['payroll' => $message]);
    }

    protected function flag(string $name): ?string
    {
        return $this->db()->table('app_flags')->where('name', $name)->value('value');
    }

    protected function setFlag(string $name, string $value): void
    {
        $this->db()->statement('INSERT INTO app_flags (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$name, $value]);
    }

    protected function groupIsUnder(int $groupId, int $rootId, array $parents): bool
    {
        for ($i = 0; $groupId > 0 && $i < 20; $i++) {
            if ($groupId === $rootId) {
                return true;
            }
            $groupId = (int) ($parents[$groupId] ?? 0);
        }

        return false;
    }

    /** One of the payroll accounts: the remembered one, else an existing account with a fitting name, else a new one. */
    public function ledgerFor(string $key): Ledger
    {
        $sp = self::LEDGERS[$key];
        $flag = "construction_{$key}_ledger_id";
        if (($id = (int) $this->flag($flag)) > 0 && ($l = Ledger::find($id))) {
            return $l;
        }
        $root = (int) $this->chart->topLevelGroupId($sp['root']);
        if (! $root) {
            $this->fail('The '.$sp['name'].' account could not be found or made - the chart of accounts has no '.$sp['root'].' section.');
        }
        $parents = Group::pluck('parent_id', 'id')->all();
        foreach ($sp['patterns'] as $pattern) {
            $q = Ledger::where('type', 0)->where('name', 'like', "%$pattern%");
            foreach ($sp['exclude'] as $ex) {
                $q->where('name', 'not like', "%$ex%");
            }
            foreach ($q->orderBy('id')->get() as $row) {
                if ($this->groupIsUnder((int) $row->group_id, $root, $parents)) {
                    $this->setFlag($flag, (string) $row->id);

                    return $row;
                }
            }
        }
        $g = null;
        foreach ($sp['group_patterns'] as $pattern) {
            $g = Group::where('code', 'like', $sp['group_prefix'].'%')->where('name', 'like', "%$pattern%")->orderByRaw('LENGTH(code)')->orderBy('code')->first();
            if ($g) {
                break;
            }
        }
        if (! $g || ! $this->groupIsUnder((int) $g->id, $root, $parents)) {
            $g = Group::find($root);
        }
        $n = 0;
        foreach (Ledger::where('group_id', $g->id)->pluck('code') as $code) {
            $n = max($n, (int) substr(strrchr('-'.$code, '-'), 1));
        }
        do {
            $n++;
            $code = $g->code.'-'.sprintf('%04d', $n);
        } while (Ledger::where('code', $code)->exists() && $n < 9999);
        $l = Ledger::create(['group_id' => $g->id, 'name' => $sp['name'], 'code' => $code, 'op_balance' => '0.00', 'op_balance_dc' => $sp['dc'], 'type' => 0,
            'reconciliation' => 0, 'notes' => $sp['notes'].' (created automatically)']);
        $this->setFlag($flag, (string) $l->id);
        ActivityLog::add('Automatic setup: created the account "'.$sp['name'].'" ('.$code.')');

        return $l;
    }

    /** The three accounts together, each as an id. */
    protected function accounts(): array
    {
        $out = [];
        foreach (self::PAYROLL_KEYS as $k) {
            $out[$k] = (int) $this->ledgerFor($k)->id;
        }

        return $out;
    }

    /**
     * Posts one balanced entry. $lines = [[ledger_id, amount, 'D'|'C', narration, project_id|null], ...]. Zero lines are left out.
     * Returns the entry id.
     */
    protected function post(string $typeLabel, string $date, string $notes, array $lines): int
    {
        $type = EntryType::where('label', $typeLabel)->first() ?? EntryType::where('label', 'journal')->first();
        if (! $type) {
            $this->fail('This company has no Journal entry type, so nothing can be posted.');
        }
        $dr = $cr = '0.00';
        foreach ($lines as $l) {
            $l[2] === 'D' ? $dr = Money::add($dr, $l[1]) : $cr = Money::add($cr, $l[1]);
        }
        if (Money::cmp($dr, $cr) !== 0) {
            $this->fail('The entry would not balance, so nothing was posted.');
        }
        $entryId = $this->db()->table('entries')->insertGetId(['entrytype_id' => $type->id, 'tag_id' => null, 'number' => $this->entries->nextNumber($type->id), 'date' => $date,
            'dr_total' => $dr, 'cr_total' => $cr, 'notes' => $notes]);
        $withProjects = Tables::has('entryitems', 'project_id') && Tables::has('entryitems', 'cost_type');
        $rows = [];
        foreach ($lines as $l) {
            if (! Money::positive($l[1])) {
                continue;
            }
            $row = ['entry_id' => $entryId, 'ledger_id' => (int) $l[0], 'amount' => $l[1], 'dc' => $l[2], 'narration' => mb_substr($l[3], 0, 500)];
            if ($withProjects) {
                $row['project_id'] = ! empty($l[4]) ? (int) $l[4] : null;
                $row['cost_type'] = ! empty($l[4]) ? 'labour' : null;
            }
            $rows[] = $row;
        }
        $this->db()->table('entryitems')->insert($rows);

        return (int) $entryId;
    }

    protected function dropEntry($entryId): void
    {
        if (! empty($entryId)) {
            $this->db()->table('entryitems')->where('entry_id', $entryId)->delete();
            $this->db()->table('entries')->where('id', $entryId)->delete();
        }
    }

    /** A cash / bank account (type 1) by id, or null. */
    public function bank($id): ?Ledger
    {
        $b = Ledger::find((int) $id);

        return $b && (int) $b->type === 1 ? $b : null;
    }

    public function bankOptions(): array
    {
        return Ledger::where('type', 1)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** The cash/bank account that suits a payment method (EVC -> an "EVC" account, cash -> "Cash", bank -> the rest). */
    public function accountFor(?string $method, array $opts): ?int
    {
        $want = $method === 'evc' ? '/evc|zaad|sahal|e-?dahab|mobile/i' : ($method === 'cash' ? '/cash|petty/i' : null);
        foreach ($opts as $id => $name) {
            if ($want && preg_match($want, $name)) {
                return (int) $id;
            }
            if (! $want && ! preg_match('/cash|petty|evc|zaad|sahal|e-?dahab|mobile/i', $name)) {
                return (int) $id;
            }
        }

        return $opts ? (int) array_key_first($opts) : null;
    }

    /** Projects an employee can be tagged to (only when Construction is on and the table exists): id => "#3 Name". */
    public function projectOptions(): array
    {
        if (! Setting::first()?->construction_enabled || ! Tables::has('projects')) {
            return [];
        }
        $out = [];
        foreach ($this->db()->table('projects')->whereIn('status', ['active', 'on_hold'])->orderByDesc('number')->get(['id', 'number', 'name']) as $p) {
            $out[$p->id] = "#{$p->number} {$p->name}";
        }

        return $out;
    }

    // ---- employees ------------------------------------------------------------------------

    public function employees(bool $activeOnly = false)
    {
        return $this->db()->table('pay_employees')->when($activeOnly, fn ($q) => $q->where('status', 'active'))->orderBy('name')->get();
    }

    public function employee($id): ?object
    {
        return $this->db()->table('pay_employees')->where('id', (int) $id)->first();
    }

    public function departments(): array
    {
        return $this->db()->table('pay_employees')->whereNotNull('department')->where('department', '<>', '')->distinct()->orderBy('department')->pluck('department')->all();
    }

    /** Add ($id null) or change an employee. */
    public function saveEmployee(array $d, ?int $id = null): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $this->fail("Enter the employee's name.");
        }
        $salary = $this->moneyIn($d['salary'] ?? '');
        if ($salary === null) {
            $this->fail('The monthly salary must be a number.');
        }
        $email = trim((string) ($d['email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('The email address is not valid.');
        }
        $start = trim((string) ($d['start_date'] ?? ''));
        if ($start !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || date('Y-m-d', strtotime($start)) !== $start)) {
            $this->fail('Enter a real start date.');
        }
        $projects = $this->projectOptions();
        $pid = ! empty($d['project_id']) && isset($projects[(int) $d['project_id']]) ? (int) $d['project_id'] : null;
        $row = ['name' => $name, 'position' => mb_substr(trim((string) ($d['position'] ?? '')), 0, 100), 'department' => mb_substr(trim((string) ($d['department'] ?? '')), 0, 100),
            'phone' => mb_substr(trim((string) ($d['phone'] ?? '')), 0, 50), 'email' => $email, 'salary' => $salary, 'method' => isset(self::METHODS[$d['method'] ?? '']) ? $d['method'] : 'cash',
            'pay_account' => mb_substr(trim((string) ($d['pay_account'] ?? '')), 0, 100), 'project_id' => $pid, 'start_date' => $start ?: null,
            'status' => ($d['status'] ?? '') === 'inactive' ? 'inactive' : 'active', 'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 500)];
        if ($id) {
            $this->db()->table('pay_employees')->where('id', $id)->update($row);
            ActivityLog::add("Payroll: changed employee $name");

            return $id;
        }
        $id = (int) $this->db()->table('pay_employees')->insertGetId($row + ['created_at' => now()]);
        ActivityLog::add("Payroll: added employee $name");

        return $id;
    }

    // ---- advances and loans -----------------------------------------------------------------

    public function advances(?int $employeeId = null, bool $openOnly = false)
    {
        return $this->db()->table('pay_advances as a')->leftJoin('pay_employees as e', 'e.id', '=', 'a.employee_id')->leftJoin('ledgers as l', 'l.id', '=', 'a.ledger_id')
            ->when($employeeId, fn ($q) => $q->where('a.employee_id', $employeeId))->when($openOnly, fn ($q) => $q->where('a.status', 'open'))
            ->orderByDesc('a.date')->orderByDesc('a.id')->get(['a.*', 'e.name as employee_name', 'l.name as ledger_name']);
    }

    public function balanceOf(object $a): string
    {
        return Money::sub($a->amount, $a->repaid);
    }

    /** What a pay run should take back from an employee this month. */
    public function suggestedRepay(int $employeeId): string
    {
        $t = '0.00';
        foreach ($this->db()->table('pay_advances')->where('employee_id', $employeeId)->where('status', 'open')->get() as $a) {
            $left = $this->balanceOf($a);
            // the monthly instalment when one is set and less than what is left; otherwise all of it
            $t = Money::add($t, Money::positive($a->monthly) && Money::cmp($a->monthly, $left) < 0 ? $a->monthly : $left);
        }

        return $t;
    }

    public function openBalance(int $employeeId): string
    {
        $t = '0.00';
        foreach ($this->db()->table('pay_advances')->where('employee_id', $employeeId)->where('status', 'open')->get() as $a) {
            $t = Money::add($t, $this->balanceOf($a));
        }

        return $t;
    }

    /** Money given to an employee now, to be taken back in coming pay runs: Dr Staff Advances and Loans / Cr cash-bank. */
    public function addAdvance(array $d): int
    {
        $emp = $this->employee($d['employee_id'] ?? 0) ?? $this->fail('Choose the employee.');
        $kind = ($d['kind'] ?? '') === 'loan' ? 'loan' : 'advance';
        $amount = $this->moneyIn($d['amount'] ?? '');
        if ($amount === null || ! Money::positive($amount)) {
            $this->fail('Enter the amount given.');
        }
        $monthly = $this->moneyIn($d['monthly'] ?? '');
        if ($monthly === null) {
            $this->fail('The monthly repayment must be a number.');
        }
        if ($kind === 'loan' && ! Money::positive($monthly)) {
            $this->fail('Enter how much of the loan to take back each month.');
        }
        $date = (string) ($d['date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date) {
            $this->fail('Enter the date.');
        }
        $this->guard($date);
        $bank = $this->bank($d['ledger_id'] ?? 0) ?? $this->fail('Choose the cash or bank account it was paid from.');
        $label = ($kind === 'loan' ? 'Staff loan' : 'Salary advance').' - '.$emp->name;

        $id = $this->db()->transaction(function () use ($emp, $kind, $date, $amount, $monthly, $bank, $label, $d) {
            $staff = (int) $this->ledgerFor('staff_advances')->id;
            $entry = $this->post('payment', $date, $label, [[$staff, $amount, 'D', $label, null], [$bank->id, $amount, 'C', $label, null]]);

            return (int) $this->db()->table('pay_advances')->insertGetId(['employee_id' => $emp->id, 'kind' => $kind, 'date' => $date, 'amount' => $amount, 'monthly' => $monthly, 'repaid' => '0.00',
                'ledger_id' => $bank->id, 'entry_id' => $entry, 'status' => 'open', 'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 255), 'created_at' => now()]);
        });
        ActivityLog::add("Payroll: $label $amount from {$bank->name}");

        return $id;
    }

    public function voidAdvance(int $id, ?string $reason = null): void
    {
        $this->db()->transaction(function () use ($id, $reason) {
            $a = $this->db()->table('pay_advances')->where('id', $id)->lockForUpdate()->first();
            if (! $a || $a->status === 'void') {
                $this->fail('That advance is already void.');
            }
            if (Money::positive($a->repaid)) {
                $this->fail('Part of it was already taken back in a pay run - void that pay run first.');
            }
            $this->guard($a->date);
            $this->dropEntry($a->entry_id);
            $this->db()->table('pay_advances')->where('id', $id)->update(['status' => 'void', 'entry_id' => null]);
            ActivityLog::add("Payroll: voided {$a->kind} #$id");
            $this->logReason("{$a->kind} #$id", $reason);
        });
    }

    protected function logReason(string $what, ?string $reason): void
    {
        if (($reason = trim((string) $reason)) !== '') {
            ActivityLog::add("Payroll: reason for voiding $what: ".mb_substr($reason, 0, 180));
        }
    }

    // ---- pay runs -------------------------------------------------------------------------

    public function runs()
    {
        return $this->db()->table('pay_runs')->orderByDesc('period')->orderByDesc('id')->get();
    }

    public function run(int $id): ?object
    {
        $r = $this->db()->table('pay_runs')->where('id', $id)->first();
        if (! $r) {
            return null;
        }
        $r->lines = $this->db()->table('pay_lines as l')->leftJoin('pay_employees as e', 'e.id', '=', 'l.employee_id')->where('l.run_id', $id)->orderBy('e.name')
            ->get(['l.*', 'e.name', 'e.position', 'e.department', 'e.method', 'e.pay_account', 'e.phone', 'e.email', 'e.project_id'])->all();
        $r->payments = $this->db()->table('pay_payments as p')->leftJoin('ledgers as l', 'l.id', '=', 'p.ledger_id')->where('p.run_id', $id)->orderBy('p.id')->get(['p.*', 'l.name as ledger_name'])->all();

        return $r;
    }

    /** What the pay runs list shows: 'draft' | 'approved' | 'part' | 'paid' | 'void'. */
    public function displayStatus(object $run, string $paid): string
    {
        if ($run->status !== 'approved') {
            return $run->status;
        }
        if (Money::cmp($paid, $run->net) >= 0 && Money::positive($run->net)) {
            return 'paid';
        }

        return Money::positive($paid) ? 'part' : 'approved';
    }

    /** Totals of the home page: active staff, monthly salaries, approved-not-paid, owed in advances; plus what each run has paid. */
    public function homeFigures(): array
    {
        $runs = $this->runs();
        $paidBy = $this->db()->table('pay_lines')->selectRaw('run_id, SUM(paid) as paid')->groupBy('run_id')->pluck('paid', 'run_id')->all();
        $unpaid = '0.00';
        foreach ($runs as $r) {
            if ($r->status === 'approved') {
                $unpaid = Money::add($unpaid, Money::sub($r->net, $paidBy[$r->id] ?? 0));
            }
        }
        $owed = '0.00';
        foreach ($this->advances(null, true) as $a) {
            $owed = Money::add($owed, $this->balanceOf($a));
        }
        $next = date('Y-m');
        foreach ($runs as $r) {
            if ($r->status !== 'void') {
                $next = date('Y-m', strtotime($r->period.'-01 +1 month'));
                break;
            }
        }

        return ['runs' => $runs, 'paid_by' => $paidBy, 'unpaid' => $unpaid, 'owed' => $owed, 'next' => $next,
            'employee_count' => $this->db()->table('pay_employees')->where('status', 'active')->count(),
            'monthly' => (string) ($this->db()->table('pay_employees')->where('status', 'active')->sum('salary') ?: '0.00')];
    }

    /** Works out one line from its inputs: [absent_amount, gross, net]. */
    public function calcLine($salary, $allowance, $overtime, $bonus, $absentDays, $repay): array
    {
        $absent = Money::round(bcdiv(bcmul((string) $salary, (string) $absentDays, 8), '30', 8));
        if (Money::cmp($absent, $salary) > 0) {
            $absent = Money::add($salary, 0);
        }
        $gross = Money::add(Money::add(Money::add(Money::sub($salary, $absent), $allowance), $overtime), $bonus);

        return ['absent_amount' => $absent, 'gross' => $gross, 'net' => Money::sub($gross, $repay)];
    }

    protected function refreshTotals(int $runId): void
    {
        $t = $this->db()->table('pay_lines')->where('run_id', $runId)->selectRaw('COALESCE(SUM(gross),0) as gross, COALESCE(SUM(repay),0) as repay, COALESCE(SUM(net),0) as net')->first();
        $this->db()->table('pay_runs')->where('id', $runId)->update(['gross' => $t->gross, 'repay' => $t->repay, 'net' => $t->net]);
    }

    /** A draft for a month with a line for every active employee. Returns the run id. */
    public function newRun(string $period, string $date): int
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $this->fail('Choose the month.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date) {
            $this->fail('Enter the pay date.');
        }
        $this->guard(null);
        if ($this->db()->table('pay_runs')->where('period', $period)->where('status', '<>', 'void')->exists()) {
            $this->fail('There is already a pay run for '.$this->monthLabel($period).'. Open it from the list.');
        }
        $emps = $this->employees(true);
        if ($emps->isEmpty()) {
            $this->fail('Add your employees first.');
        }
        $runId = $this->db()->transaction(function () use ($period, $date, $emps) {
            $runId = (int) $this->db()->table('pay_runs')->insertGetId(['period' => $period, 'date' => $date, 'status' => 'draft', 'created_by' => $this->who(), 'created_at' => now()]);
            foreach ($emps as $e) {
                $repay = $this->suggestedRepay($e->id);
                $c = $this->calcLine($e->salary, 0, 0, 0, 0, $repay);
                if (Money::cmp($repay, $c['gross']) > 0) {
                    $repay = $c['gross'];
                    $c = $this->calcLine($e->salary, 0, 0, 0, 0, $repay);
                }
                $this->db()->table('pay_lines')->insert(['run_id' => $runId, 'employee_id' => $e->id, 'salary' => $e->salary, 'allowance' => '0.00', 'overtime' => '0.00', 'bonus' => '0.00',
                    'absent_days' => '0.00', 'repay' => $repay] + $c);
            }
            $this->refreshTotals($runId);

            return $runId;
        });
        ActivityLog::add('Payroll: started the '.$this->monthLabel($period).' pay run');

        return $runId;
    }

    /** Saves the draft's lines. $posted = line_id => [allowance, overtime, bonus, absent_days, repay, note]. */
    public function saveDraft(int $runId, array $posted, ?string $date = null): void
    {
        $this->db()->transaction(function () use ($runId, $posted, $date) {
            $locked = $this->db()->table('pay_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'draft') {
                $this->fail('Only a draft can be changed.');
            }
            if ($date !== null) {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date) {
                    $this->fail('Enter the pay date.');
                }
                $this->db()->table('pay_runs')->where('id', $runId)->update(['date' => $date]);
            }
            $run = $this->run($runId);
            foreach ($run->lines as $line) {
                $in = $posted[$line->id] ?? null;
                if (! $in) {
                    continue;
                }
                $v = [];
                foreach (['allowance', 'overtime', 'bonus', 'repay'] as $k) {
                    $v[$k] = $this->moneyIn($in[$k] ?? '');
                    if ($v[$k] === null) {
                        $this->fail("{$line->name}: $k must be a number.");
                    }
                }
                $days = str_replace(',', '.', trim((string) ($in['absent_days'] ?? '')));
                $days = $days === '' ? '0' : $days;
                if (! preg_match('/^\d+(\.\d+)?$/', $days) || bccomp($days, '31', 2) > 0) {
                    $this->fail("{$line->name}: absent days must be between 0 and 31.");
                }
                $open = $this->openBalance((int) $line->employee_id);
                if (Money::cmp($v['repay'], $open) > 0) {
                    $this->fail("{$line->name} only owes ".Money::format($open).' in advances and loans.');
                }
                $c = $this->calcLine($line->salary, $v['allowance'], $v['overtime'], $v['bonus'], $days, $v['repay']);
                if (Money::cmp($c['net'], 0) < 0) {
                    $this->fail("{$line->name}: the repayment is more than the pay.");
                }
                $this->db()->table('pay_lines')->where('id', $line->id)->update($v + ['absent_days' => bcadd($days, '0', 2), 'note' => mb_substr(trim((string) ($in['note'] ?? '')), 0, 255)] + $c);
            }
            $this->refreshTotals($runId);
        });
    }

    /** Posts the run to the books: Dr Salaries / Cr Salaries Payable and Staff Advances, and takes the repayments off the advances. */
    public function approve(int $runId): void
    {
        $month = null;
        $net = null;
        $this->db()->transaction(function () use ($runId, &$month, &$net) {
            $locked = $this->db()->table('pay_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'draft') {
                $this->fail('Only a draft can be approved.');
            }
            $run = $this->run($runId);
            $this->guard($run->date);
            if (! Money::positive($run->gross)) {
                $this->fail('There is nothing to pay in this run.');
            }
            $month = $this->monthLabel($run->period);
            $net = $run->net;
            foreach ($run->lines as $l) {
                if (Money::cmp($l->repay, $this->openBalance((int) $l->employee_id)) > 0) {
                    $this->fail("{$l->name} now owes less than the {$l->repay} taken back in this run (another run took some of it). Change the repayment and save first.");
                }
            }
            $L = $this->accounts();
            // one expense line per project, never per person: the Salaries account is seen in reports by roles without Payroll access
            $byProject = [];
            foreach ($run->lines as $l) {
                if (! Money::positive($l->gross)) {
                    continue;
                }
                $k = ! empty($l->project_id) ? (int) $l->project_id : 0;
                $byProject[$k] ??= ['0.00', 0];
                $byProject[$k][0] = Money::add($byProject[$k][0], $l->gross);
                $byProject[$k][1]++;
            }
            $lines = [];
            foreach ($byProject as $pid => [$gross, $count]) {
                $lines[] = [$L['salary_expense'], $gross, 'D', "Salaries $month ($count employee".($count === 1 ? '' : 's').')', $pid ?: null];
            }
            $lines[] = [$L['salary_payable'], $run->net, 'C', "Net pay $month", null];
            $lines[] = [$L['staff_advances'], $run->repay, 'C', "Advances and loans taken back $month", null];
            $entry = $this->post('journal', $run->date, "Payroll $month", $lines);

            // repayments: oldest advance first
            foreach ($run->lines as $l) {
                $left = $l->repay;
                if (! Money::positive($left)) {
                    continue;
                }
                $adv = $this->db()->table('pay_advances')->where('employee_id', $l->employee_id)->where('status', 'open')->orderBy('date')->orderBy('id')->lockForUpdate()->get();
                foreach ($adv as $a) {
                    if (! Money::positive($left)) {
                        break;
                    }
                    $bal = $this->balanceOf($a);
                    $take = Money::cmp($bal, $left) < 0 ? $bal : $left;
                    $this->db()->table('pay_repayments')->insert(['run_id' => $runId, 'line_id' => $l->id, 'advance_id' => $a->id, 'amount' => $take]);
                    $repaid = Money::add($a->repaid, $take);
                    $this->db()->table('pay_advances')->where('id', $a->id)->update(['repaid' => $repaid, 'status' => Money::cmp($repaid, $a->amount) >= 0 ? 'paid' : 'open']);
                    $left = Money::sub($left, $take);
                }
            }
            $this->db()->table('pay_runs')->where('id', $runId)->update(['status' => 'approved', 'entry_id' => $entry, 'approved_at' => now()]);
        });
        ActivityLog::add("Payroll: approved $month, net $net");
    }

    /** Pays everyone still unpaid ($lineId null) or one employee: Dr Salaries Payable / Cr cash-bank. */
    public function pay(int $runId, ?int $lineId, string $date, $ledgerId): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date) {
            $this->fail('Enter the payment date.');
        }
        $this->guard($date);
        $bank = $this->bank($ledgerId) ?? $this->fail('Choose the cash or bank account to pay from.');
        $label = null;
        $total = null;
        $this->db()->transaction(function () use ($runId, $lineId, $date, $bank, &$label, &$total) {
            $locked = $this->db()->table('pay_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'approved') {
                $this->fail('Approve the pay run before paying it.');
            }
            $run = $this->run($runId);
            $pay = [];
            $total = '0.00';
            foreach ($run->lines as $l) {
                if ($lineId && (int) $l->id !== $lineId) {
                    continue;
                }
                $due = Money::sub($l->net, $l->paid);
                if (Money::positive($due)) {
                    $pay[$l->id] = [$due, $l->name];
                    $total = Money::add($total, $due);
                }
            }
            if (! $pay) {
                $this->fail('Nothing is left to pay.');
            }
            $label = 'Salaries paid '.$this->monthLabel($run->period).($lineId ? ' - '.reset($pay)[1] : '');
            $payable = (int) $this->ledgerFor('salary_payable')->id;
            $entry = $this->post('payment', $date, $label, [[$payable, $total, 'D', $label, null], [$bank->id, $total, 'C', $label, null]]);
            $pid = (int) $this->db()->table('pay_payments')->insertGetId(['run_id' => $runId, 'line_id' => $lineId ?: null, 'date' => $date, 'amount' => $total, 'ledger_id' => $bank->id, 'entry_id' => $entry, 'created_at' => now()]);
            foreach ($pay as $id => $p) {
                $this->db()->table('pay_payment_lines')->insert(['payment_id' => $pid, 'line_id' => $id, 'amount' => $p[0]]);
                $this->db()->table('pay_lines')->where('id', $id)->update(['paid' => DB::raw('paid + '.$p[0])]);
            }
        });
        ActivityLog::add("Payroll: $label $total from {$bank->name}");
    }

    /** Undoes one payment: its entry goes and the pay is due again. */
    public function voidPayment(int $paymentId, ?string $reason = null): int
    {
        return $this->db()->transaction(function () use ($paymentId, $reason) {
            $p = $this->db()->table('pay_payments')->where('id', $paymentId)->lockForUpdate()->first() ?? $this->fail('That payment no longer exists.');
            $this->guard($p->date);
            foreach ($this->db()->table('pay_payment_lines')->where('payment_id', $paymentId)->get() as $pl) {
                $this->db()->table('pay_lines')->where('id', $pl->line_id)->update(['paid' => DB::raw('paid - '.$pl->amount)]);
            }
            $this->db()->table('pay_payment_lines')->where('payment_id', $paymentId)->delete();
            $this->dropEntry($p->entry_id);
            $this->db()->table('pay_payments')->where('id', $paymentId)->delete();
            ActivityLog::add("Payroll: undid a salary payment of {$p->amount}");
            $this->logReason("salary payment #$paymentId", $reason);

            return (int) $p->run_id;
        });
    }

    /** Voids an approved run (its payments must be undone first); a draft is simply deleted. */
    public function voidRun(int $runId, ?string $reason = null): string
    {
        return $this->db()->transaction(function () use ($runId, $reason) {
            $run = $this->db()->table('pay_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $run || $run->status === 'void') {
                $this->fail('That pay run is already void.');
            }
            if ($run->status === 'draft') {
                $this->db()->table('pay_lines')->where('run_id', $runId)->delete();
                $this->db()->table('pay_runs')->where('id', $runId)->delete();
                ActivityLog::add("Payroll: deleted the draft pay run for {$run->period}");

                return 'draft';
            }
            if ($this->db()->table('pay_payments')->where('run_id', $runId)->exists()) {
                $this->fail('Undo the salary payments of this run first (under Payments on this page).');
            }
            $this->guard($run->date);
            foreach ($this->db()->table('pay_repayments')->where('run_id', $runId)->get() as $r) {
                $a = $this->db()->table('pay_advances')->where('id', $r->advance_id)->lockForUpdate()->first();
                if ($a) {
                    $this->db()->table('pay_advances')->where('id', $a->id)->update(['repaid' => Money::sub($a->repaid, $r->amount), 'status' => $a->status === 'void' ? 'void' : 'open']);
                }
            }
            $this->db()->table('pay_repayments')->where('run_id', $runId)->delete();
            $this->dropEntry($run->entry_id);
            $this->db()->table('pay_runs')->where('id', $runId)->update(['status' => 'void', 'entry_id' => null]);
            ActivityLog::add("Payroll: voided the {$run->period} pay run");
            $this->logReason("pay run #$runId", $reason);

            return 'approved';
        });
    }

    // ---- payslips -------------------------------------------------------------------------

    /** One payslip's figures, or null when the line or its run is gone / void. */
    public function slip(int $lineId): ?array
    {
        $l = $this->db()->table('pay_lines')->where('id', $lineId)->first();
        $run = $l ? $this->run((int) $l->run_id) : null;
        if (! $run || $run->status === 'void') {
            return null;
        }
        foreach ($run->lines as $x) {
            if ((int) $x->id === $lineId) {
                return ['run' => $run, 'line' => $x, 'month' => $this->monthLabel($run->period), 'owes' => $this->openBalance((int) $x->employee_id)];
            }
        }

        return null;
    }

    public function slipFile(array $d): string
    {
        return 'Payslip '.trim(preg_replace('/[^A-Za-z0-9 _.-]/', '', $d['line']->name)).' '.$d['run']->period.'.pdf';
    }

    /** The rows of a payslip as [label, amount (signed), kind]. */
    public function slipRows(object $l): array
    {
        $num = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
        $rows = [['Basic salary', $l->salary, '']];
        if (Money::positive($l->absent_days)) {
            $rows[] = ['Absent ('.$num($l->absent_days).' day'.((float) $l->absent_days == 1.0 ? '' : 's').')', Money::neg($l->absent_amount), ''];
        }
        foreach (['allowance' => 'Allowance', 'overtime' => 'Overtime', 'bonus' => 'Bonus'] as $k => $label) {
            if (Money::positive($l->$k)) {
                $rows[] = [$label, $l->$k, ''];
            }
        }
        $rows[] = ['Gross pay', $l->gross, 'is-total'];
        if (Money::positive($l->repay)) {
            $rows[] = ['Advance / loan taken back', Money::neg($l->repay), ''];
        }
        $rows[] = ['Net pay', $l->net, 'is-total'];

        return $rows;
    }

    // ---- reports --------------------------------------------------------------------------

    public const VIEWS = ['summary' => 'Summary by month', 'employees' => 'By employee', 'department' => 'By department', 'project' => 'By project', 'advances' => 'Advances & loans owed'];

    /**
     * One report as a table. Only approved runs count. Returns ['title', 'head', 'money' (column indexes that are money),
     * 'rows' => [['cells' => [...], 'url' => route name + params|null]], 'total' => [column => sum]].
     */
    public function report(string $view, string $from, string $to): array
    {
        $rows = [];
        $head = [];
        $money = [];
        $skipTotal = [];
        $approved = fn () => $this->db()->table('pay_runs')->where('status', 'approved')->where('period', '>=', $from)->where('period', '<=', $to);
        if ($view === 'summary') {
            $title = 'Payroll summary';
            $head = ['Month', 'Employees', 'Gross pay', 'Taken back', 'Net pay', 'Paid', 'Still to pay'];
            $money = [2, 3, 4, 5, 6];
            $agg = $this->db()->table('pay_lines')->selectRaw('run_id, COUNT(*) as n, SUM(paid) as paid')->groupBy('run_id')->get()->keyBy('run_id');
            foreach ($approved()->orderBy('period')->get() as $r) {
                $paid = (string) ($agg[$r->id]->paid ?? '0.00');
                $rows[] = ['cells' => [$this->monthLabel($r->period), (int) ($agg[$r->id]->n ?? 0), $r->gross, $r->repay, $r->net, Money::add($paid, 0), Money::sub($r->net, $paid)], 'url' => ['payroll.runs.show', $r->id]];
            }
        } elseif ($view === 'employees') {
            $title = 'Pay by employee';
            $head = ['Employee', 'Department', 'Months', 'Gross pay', 'Taken back', 'Net pay'];
            $money = [3, 4, 5];
            $agg = [];
            $lines = $this->db()->table('pay_lines as l')->join('pay_runs as r', 'r.id', '=', 'l.run_id')->leftJoin('pay_employees as e', 'e.id', '=', 'l.employee_id')
                ->where('r.status', 'approved')->where('r.period', '>=', $from)->where('r.period', '<=', $to)->orderBy('e.name')->get(['l.*', 'e.name', 'e.department']);
            foreach ($lines as $l) {
                $k = (int) $l->employee_id;
                $agg[$k] ??= [$l->name, $l->department, 0, '0.00', '0.00', '0.00'];
                $agg[$k][2]++;
                $agg[$k][3] = Money::add($agg[$k][3], $l->gross);
                $agg[$k][4] = Money::add($agg[$k][4], $l->repay);
                $agg[$k][5] = Money::add($agg[$k][5], $l->net);
            }
            foreach ($agg as $k => $a) {
                $rows[] = ['cells' => $a, 'url' => ['payroll.employees.show', $k]];
            }
        } elseif ($view === 'department' || $view === 'project') {
            $title = $view === 'department' ? 'Salary cost by department' : 'Salary cost by project';
            $head = [$view === 'department' ? 'Department' : 'Project', 'Gross pay'];
            $money = [1];
            $names = [];
            if ($view === 'project' && Tables::has('projects')) {
                $names = $this->db()->table('projects')->pluck('name', 'id')->all();
            }
            $lines = $this->db()->table('pay_lines as l')->join('pay_runs as r', 'r.id', '=', 'l.run_id')->leftJoin('pay_employees as e', 'e.id', '=', 'l.employee_id')
                ->where('r.status', 'approved')->where('r.period', '>=', $from)->where('r.period', '<=', $to)->get(['l.gross', 'e.department', 'e.project_id']);
            $out = [];
            foreach ($lines as $l) {
                $k = $view === 'department' ? (($l->department ?? '') !== '' ? $l->department : 'No department') : (! empty($l->project_id) && isset($names[$l->project_id]) ? $names[$l->project_id] : 'No project');
                $out[$k] = Money::add($out[$k] ?? '0.00', $l->gross);
            }
            ksort($out);
            foreach ($out as $k => $v) {
                $rows[] = ['cells' => [$k, $v], 'url' => null];
            }
        } else {
            $title = 'Advances and loans owed';
            $head = ['Employee', 'Kind', 'Given on', 'Amount', 'Each month', 'Taken back', 'Still owed'];
            $money = [3, 4, 5, 6];
            $skipTotal = [4];
            foreach ($this->advances(null, true) as $a) {
                $rows[] = ['cells' => [$a->employee_name, $a->kind === 'loan' ? 'Loan' : 'Advance', \App\Support\Money::date($a->date), $a->amount, $a->monthly, $a->repaid, $this->balanceOf($a)], 'url' => ['payroll.employees.show', $a->employee_id]];
            }
        }
        $total = [];
        foreach ($rows as $r) {
            foreach ($money as $i) {
                if (! in_array($i, $skipTotal, true)) {
                    $total[$i] = Money::add($total[$i] ?? '0.00', $r['cells'][$i]);
                }
            }
        }

        return ['title' => $title, 'head' => $head, 'money' => $money, 'rows' => $rows, 'total' => $total];
    }

    /** Approved lines of one employee, newest month first. */
    public function history(int $employeeId)
    {
        return $this->db()->table('pay_lines as l')->join('pay_runs as r', 'r.id', '=', 'l.run_id')->where('r.status', 'approved')->where('l.employee_id', $employeeId)
            ->orderByDesc('r.period')->get(['l.*', 'r.period', 'r.date']);
    }
}
