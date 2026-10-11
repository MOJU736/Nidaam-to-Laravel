<?php

namespace App\Services\Construction;

use App\Models\Tenant\EntryType;
use App\Models\Tenant\Ledger;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Services\Payroll\PayrollService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Construction - site workers and wage sheets (legacy Wages_model). Workers are paid a daily rate (days x rate) or a monthly salary;
 * subcontractors are vendors, paid with bills. A wage sheet covers a period; each line is one worker on one project. Posting it:
 *   Dr Site Wages (one line per wage line, tagged project + Labour)  /  Cr the cash or bank it was paid from, or Wages Payable when
 * not paid yet ("Pay" later posts Dr Wages Payable / Cr cash or bank). Voiding removes both entries.
 */
class WagesService
{
    public const PAY_TYPES = ['daily' => 'Daily rate', 'monthly' => 'Monthly salary'];

    public function __construct(protected ChartOfAccounts $chart, protected EntryService $entries, protected PayrollService $ledgers) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['projects' => $message]);
    }

    protected function guard(string|array|null $date): void
    {
        if ($this->chart->companyLocked()) {
            $this->fail('This company is locked, so nothing can be posted. An administrator can unlock it under Settings.');
        }
        foreach ((array) $date as $d) {
            if ($d && ($err = $this->entries->closedPeriodError(substr($d, 0, 10)))) {
                $this->fail($err);
            }
        }
    }

    // ---- workers ----------------------------------------------------------------------------------------------------

    public function workers(bool $activeOnly = false)
    {
        return $this->db()->table('workers')->leftJoin('projects', 'projects.id', '=', 'workers.default_project_id')->when($activeOnly, fn ($q) => $q->where('workers.status', 'active'))
            ->orderBy('workers.name')->get(['workers.*', 'projects.name as project_name', 'projects.number as project_number']);
    }

    public function worker(int $id): ?object
    {
        return $this->db()->table('workers')->where('id', $id)->first();
    }

    public function saveWorker(array $d, ?int $id = null): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            $this->fail("Enter the worker's name.");
        }
        $type = array_key_exists($d['pay_type'] ?? '', self::PAY_TYPES) ? $d['pay_type'] : 'daily';
        $rate = trim(str_replace(',', '', (string) ($d['rate'] ?? '')));
        if ($rate === '' || ! is_numeric($rate) || (float) $rate < 0) {
            $this->fail('Enter the '.($type === 'daily' ? 'daily rate' : 'monthly salary').' (0 or more).');
        }
        $pid = ! empty($d['default_project_id']) ? (int) $d['default_project_id'] : null;
        if ($pid && ! $this->db()->table('projects')->where('id', $pid)->exists()) {
            $pid = null;
        }
        $row = ['name' => $name, 'phone' => trim((string) ($d['phone'] ?? '')), 'pay_type' => $type, 'rate' => Money::round($rate), 'trade' => trim((string) ($d['trade'] ?? '')),
            'default_project_id' => $pid, 'status' => ($d['status'] ?? '') === 'inactive' ? 'inactive' : 'active'];
        $verb = $id ? 'Saved' : 'Added';
        if ($id) {
            $this->db()->table('workers')->where('id', $id)->update($row);
        } else {
            $id = $this->db()->table('workers')->insertGetId($row + ['created_at' => now()]);
        }
        ActivityLog::add("$verb worker $name");

        return $id;
    }

    // ---- wage sheets --------------------------------------------------------------------------------------------------

    protected function entryType(string $label): ?int
    {
        return EntryType::where('label', $label)->value('id');
    }

    public function nextNumber(): int
    {
        return (int) $this->db()->table('wage_sheets')->max('number') + 1;
    }

    public function sheets()
    {
        return $this->db()->table('wage_sheets')->leftJoin('ledgers', 'ledgers.id', '=', 'wage_sheets.paid_ledger_id')->orderByDesc('wage_sheets.date')->orderByDesc('wage_sheets.id')
            ->get(['wage_sheets.*', 'ledgers.name as paid_ledger_name']);
    }

    public function sheet(int $id): ?object
    {
        $s = $this->db()->table('wage_sheets')->leftJoin('ledgers', 'ledgers.id', '=', 'wage_sheets.paid_ledger_id')->where('wage_sheets.id', $id)->first(['wage_sheets.*', 'ledgers.name as paid_ledger_name']);
        if ($s) {
            $s->lines = $this->db()->table('wage_lines')->leftJoin('workers', 'workers.id', '=', 'wage_lines.worker_id')->leftJoin('projects', 'projects.id', '=', 'wage_lines.project_id')
                ->where('wage_lines.sheet_id', $id)->orderBy('wage_lines.id')->get(['wage_lines.*', 'workers.name as worker_name', 'workers.pay_type', 'projects.name as project_name', 'projects.number as project_number']);
        }

        return $s;
    }

    /**
     * Post a wage sheet. $d: date_from, date_to, date, paid_ledger_id ('' = not paid yet), notes, lines = [worker_id, project_id, days, rate, amount, note].
     * Daily workers: amount = days x rate; a daily line with no days is skipped. Returns the sheet id.
     */
    public function createSheet(array $d): int
    {
        foreach (['date_from' => 'the period start', 'date_to' => 'the period end', 'date' => 'the sheet date'] as $k => $label) {
            if (empty($d[$k])) {
                $this->fail("Enter $label.");
            }
        }
        if ($d['date_to'] < $d['date_from']) {
            $this->fail('The period ends before it starts.');
        }
        $this->guard($d['date']);
        $lines = [];
        $total = '0.00';
        foreach ((array) ($d['lines'] ?? []) as $l) {
            $wid = (int) ($l['worker_id'] ?? 0);
            if (! $wid) {
                continue;
            }
            $w = $this->worker($wid) ?? $this->fail('One of the workers no longer exists.');
            $rate = trim(str_replace(',', '', (string) (($l['rate'] ?? '') !== '' ? $l['rate'] : $w->rate)));
            if (! is_numeric($rate) || (float) $rate < 0) {
                $this->fail("The rate for {$w->name} is not a number.");
            }
            $days = null;
            if ($w->pay_type === 'daily') {
                $days = trim(str_replace(',', '.', (string) ($l['days'] ?? '')));
                if ($days === '' || ! is_numeric($days) || (float) $days <= 0) {
                    continue; // no days worked = no line
                }
                if ((float) $days > 62) {
                    $this->fail("{$w->name}: $days days is more than the period can have.");
                }
                $amount = Money::mul($days, $rate);
            } else {
                $amount = trim(str_replace(',', '', (string) (($l['amount'] ?? '') !== '' ? $l['amount'] : $rate)));
                if (! is_numeric($amount) || (float) $amount <= 0) {
                    continue;
                }
                $amount = Money::round($amount);
            }
            $pid = ! empty($l['project_id']) ? (int) $l['project_id'] : null;
            if ($pid && ! $this->db()->table('projects')->where('id', $pid)->exists()) {
                $pid = null;
            }
            $lines[] = ['worker_id' => $wid, 'worker_name' => $w->name, 'project_id' => $pid, 'days' => $days, 'rate' => Money::round($rate), 'amount' => $amount, 'note' => trim((string) ($l['note'] ?? ''))];
            $total = Money::add($total, $amount);
        }
        if (! $lines || ! Money::positive($total)) {
            $this->fail('Add at least one worker with days worked (or an amount for monthly staff).');
        }

        $wages = $this->ledgers->ledgerFor('wages_expense');
        $paid = ! empty($d['paid_ledger_id']);
        if ($paid) {
            $bank = Ledger::find((int) $d['paid_ledger_id']);
            if (! $bank || (int) $bank->type !== 1) {
                $this->fail('Choose the cash or bank account the wages were paid from.');
            }
            $creditLedger = (int) $bank->id;
            $et = $this->entryType('payment');
        } else {
            $creditLedger = (int) $this->ledgers->ledgerFor('wages_payable')->id;
            $et = $this->entryType('journal');
        }
        if (! $et) {
            $this->fail('The '.($paid ? 'Payment' : 'Journal').' entry type is missing. Check Settings → Entry types.');
        }

        $number = $this->nextNumber();
        $label = "Wage sheet $number (".Money::date($d['date_from']).' - '.Money::date($d['date_to']).')';
        $sheetId = $this->db()->transaction(function () use ($d, $lines, $total, $wages, $creditLedger, $et, $paid, $number, $label) {
            $entryId = $this->db()->table('entries')->insertGetId(['entrytype_id' => $et, 'tag_id' => null, 'number' => $this->entries->nextNumber($et), 'date' => $d['date'], 'dr_total' => $total, 'cr_total' => $total, 'notes' => $label]);
            foreach ($lines as $l) {
                $this->db()->table('entryitems')->insert(['entry_id' => $entryId, 'ledger_id' => $wages->id, 'amount' => $l['amount'], 'dc' => 'D',
                    'narration' => 'Wages: '.$l['worker_name'].($l['days'] !== null ? ', '.rtrim(rtrim((string) $l['days'], '0'), '.').' days x '.$l['rate'] : '')]
                    + ($l['project_id'] ? ['project_id' => $l['project_id'], 'cost_type' => 'labour'] : []));
            }
            $this->db()->table('entryitems')->insert(['entry_id' => $entryId, 'ledger_id' => $creditLedger, 'amount' => $total, 'dc' => 'C', 'narration' => $label.($paid ? '' : ' - not paid yet')]);
            $sheetId = $this->db()->table('wage_sheets')->insertGetId(['number' => $number, 'date_from' => $d['date_from'], 'date_to' => $d['date_to'], 'date' => $d['date'], 'total' => $total,
                'paid_ledger_id' => $paid ? $creditLedger : null, 'paid_date' => $paid ? $d['date'] : null, 'entry_id' => $entryId, 'status' => $paid ? 'paid' : 'unpaid',
                'notes' => isset($d['notes']) ? trim((string) $d['notes']) : null, 'created_at' => now()]);
            foreach ($lines as $l) {
                $this->db()->table('wage_lines')->insert(['sheet_id' => $sheetId, 'worker_id' => $l['worker_id'], 'project_id' => $l['project_id'], 'days' => $l['days'], 'rate' => $l['rate'], 'amount' => $l['amount'], 'note' => $l['note']]);
            }

            return $sheetId;
        });
        ActivityLog::add("Posted $label, total ".Money::format($total));

        return $sheetId;
    }

    /** Pay an unpaid sheet: Dr Wages Payable / Cr cash or bank. */
    public function paySheet(int $id, array $d): void
    {
        $s = $this->sheet($id);
        if (! $s || $s->status !== 'unpaid') {
            $this->fail('Only an unpaid wage sheet can be paid.');
        }
        if (empty($d['date'])) {
            $this->fail('Enter the payment date.');
        }
        $this->guard($d['date']);
        $bank = Ledger::find((int) ($d['ledger_id'] ?? 0));
        if (! $bank || (int) $bank->type !== 1) {
            $this->fail('Choose the cash or bank account the wages were paid from.');
        }
        $payable = $this->ledgers->ledgerFor('wages_payable');
        $et = $this->entryType('payment') ?? $this->fail('The Payment entry type is missing.');
        $this->db()->transaction(function () use ($id, $s, $d, $bank, $payable, $et) {
            $entryId = $this->db()->table('entries')->insertGetId(['entrytype_id' => $et, 'tag_id' => null, 'number' => $this->entries->nextNumber($et), 'date' => $d['date'], 'dr_total' => $s->total, 'cr_total' => $s->total, 'notes' => "Paid wage sheet {$s->number}"]);
            $this->db()->table('entryitems')->insert([
                ['entry_id' => $entryId, 'ledger_id' => $payable->id, 'amount' => $s->total, 'dc' => 'D', 'narration' => "Paid wage sheet {$s->number}"],
                ['entry_id' => $entryId, 'ledger_id' => $bank->id, 'amount' => $s->total, 'dc' => 'C', 'narration' => "Paid wage sheet {$s->number}"],
            ]);
            $this->db()->table('wage_sheets')->where('id', $id)->update(['status' => 'paid', 'paid_ledger_id' => $bank->id, 'paid_date' => $d['date'], 'paid_entry_id' => $entryId]);
        });
        ActivityLog::add("Paid wage sheet {$s->number} ".Money::format($s->total)." from {$bank->name}");
    }

    /** Void: both entries are removed, the sheet stays marked void. */
    public function voidSheet(int $id): void
    {
        $s = $this->sheet($id);
        if (! $s || $s->status === 'void') {
            $this->fail('That wage sheet is already void.');
        }
        $this->guard([$s->date, $s->paid_date]);
        $this->db()->transaction(function () use ($id, $s) {
            $this->db()->table('wage_sheets')->where('id', $id)->update(['status' => 'void', 'entry_id' => null, 'paid_entry_id' => null]);
            foreach ([$s->entry_id, $s->paid_entry_id] as $e) {
                if ($e) {
                    $this->db()->table('entryitems')->where('entry_id', $e)->delete();
                    $this->db()->table('entries')->where('id', $e)->delete();
                }
            }
        });
        ActivityLog::add("Voided wage sheet {$s->number}");
    }
}
