<?php

namespace App\Services\Commissions;

use App\Models\Tenant\Group;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Commissions: money received on a client's behalf (an agent business), held as "Due to Client", then settled - the client gets
 * the money less the company's commission. Ported from the table layout, the report and the entries the legacy module posted.
 *
 *   receive  (Receipt entry)   Dr bank / cash        Cr Due to Client            [+ Dr Tax Expense / Cr Taxes Payable when a receive tax % is set]
 *   settle   (Payment entry)   Dr Due to Client      Cr Commission Income, Cr bank / cash (what is sent on)
 *                              Dr Tax Expense        Cr Taxes Payable            (the settle tax % of the commission)
 *   void                       both entries are removed
 *
 * The first two lines of settle always add up: received = commission + remitted.
 */
class CommissionService
{
    public const LEDGERS = [
        'due_to_client' => ['liability', ['other current liab', 'current liab', 'liabilit'], 'Due to Client', 'C', 'Money received for clients and not yet paid on to them'],
        'commission_income' => ['income', ['operating income', 'service', 'income'], 'Commission Income', 'C', 'Commission kept when a client is paid'],
        'tax_expense' => ['expense', ['operating expenses', 'general and admin', 'expense'], 'Tax Expense', 'D', 'Tax on commission'],
        'tax_payable' => ['liability', ['tax', 'other current liab', 'current liab', 'liabilit'], 'Taxes Payable', 'C', 'Tax on commission still to be paid'],
    ];

    public function __construct(protected ChartOfAccounts $chart, protected EntryService $entries) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    protected function fail(string $message, string $key = 'commissions'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    public static function enabled(): bool
    {
        return (bool) (Setting::first()->commissions_enabled ?? false);
    }

    // ---- schema and settings --------------------------------------------------------------------

    /** Makes the two tables when missing (older companies); never call inside a transaction. */
    public function ensureSchema(): void
    {
        static $done = [];
        $key = config('database.connections.tenant.database');
        if (isset($done[$key])) {
            return;
        }
        $done[$key] = true;
        $s = Schema::connection('tenant');
        if (! $s->hasTable('commission_transactions')) {
            $this->db()->statement("CREATE TABLE `commission_transactions` (`id` BIGINT(18) NOT NULL AUTO_INCREMENT, `number` BIGINT(18) NOT NULL, `party_name` VARCHAR(255) NOT NULL, `date` DATE NOT NULL, `received_ledger_id` BIGINT(18) NOT NULL,
                `received_amount` DECIMAL(25,2) NOT NULL DEFAULT 0.00, `received_entry_id` BIGINT(18) DEFAULT NULL, `received_tax_amount` DECIMAL(25,2) DEFAULT NULL, `status` VARCHAR(20) NOT NULL DEFAULT 'pending', `settle_date` DATE DEFAULT NULL,
                `commission_amount` DECIMAL(25,2) DEFAULT NULL, `remitted_amount` DECIMAL(25,2) DEFAULT NULL, `settled_tax_amount` DECIMAL(25,2) DEFAULT NULL, `remit_ledger_id` BIGINT(18) DEFAULT NULL, `settled_entry_id` BIGINT(18) DEFAULT NULL,
                `notes` VARCHAR(500) DEFAULT NULL, `created_at` DATETIME DEFAULT NULL, PRIMARY KEY (`id`), KEY `received_ledger_id` (`received_ledger_id`), KEY `remit_ledger_id` (`remit_ledger_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        }
        if (! $s->hasTable('commission_settings')) {
            $this->db()->statement("CREATE TABLE `commission_settings` (`id` INT(1) NOT NULL, `due_to_client_ledger_id` BIGINT(18) DEFAULT NULL, `commission_income_ledger_id` BIGINT(18) DEFAULT NULL, `tax_expense_ledger_id` BIGINT(18) DEFAULT NULL,
                `tax_payable_ledger_id` BIGINT(18) DEFAULT NULL, `default_percent` DECIMAL(7,4) NOT NULL DEFAULT 0.0000, `receive_tax_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00, `settle_tax_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                `prefix` VARCHAR(20) NOT NULL DEFAULT 'COM-', PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        } elseif (! $s->hasColumn('commission_settings', 'default_percent')) {
            $this->db()->statement('ALTER TABLE `commission_settings` ADD COLUMN `default_percent` DECIMAL(7,4) NOT NULL DEFAULT 0.0000');
        }
        if (! $this->db()->table('commission_settings')->where('id', 1)->exists()) {
            $this->db()->table('commission_settings')->insert(['id' => 1]);
        }
    }

    public function settings(): object
    {
        return $this->db()->table('commission_settings')->where('id', 1)->first() ?? (object) ['id' => 1, 'due_to_client_ledger_id' => null, 'commission_income_ledger_id' => null, 'tax_expense_ledger_id' => null,
            'tax_payable_ledger_id' => null, 'default_percent' => 0, 'receive_tax_percent' => 0, 'settle_tax_percent' => 0, 'prefix' => 'COM-'];
    }

    public function saveSettings(array $d): void
    {
        $row = ['receive_tax_percent' => $this->percent($d['receive_tax_percent'] ?? 0), 'settle_tax_percent' => $this->percent($d['settle_tax_percent'] ?? 0), 'default_percent' => $this->percent($d['default_percent'] ?? 0),
            'prefix' => mb_substr(trim((string) ($d['prefix'] ?? '')) ?: 'COM-', 0, 20)];
        foreach (['due_to_client', 'commission_income', 'tax_expense', 'tax_payable'] as $k) {
            $id = (int) ($d[$k.'_ledger_id'] ?? 0);
            $row[$k.'_ledger_id'] = $id && Ledger::whereKey($id)->exists() ? $id : null;
        }
        $this->db()->table('commission_settings')->where('id', 1)->update($row);
        ActivityLog::add('Updated commission settings');
    }

    protected function percent($v): string
    {
        $v = str_replace(',', '', trim((string) $v));
        if ($v === '') {
            return '0.00';
        }
        if (! is_numeric($v) || (float) $v < 0 || (float) $v > 100) {
            $this->fail('A percentage must be a number from 0 to 100.');
        }

        return number_format((float) $v, 4, '.', '');
    }

    /** One of the four accounts: the one chosen in settings, else an existing one by name, else a new one (remembered in the settings). */
    public function ledger(string $key): Ledger
    {
        $col = $key.'_ledger_id';
        $id = (int) ($this->settings()->$col ?? 0);
        if ($id && ($l = Ledger::find($id))) {
            return $l;
        }
        [$kind, $groupPatterns, $name, $dc, $note] = self::LEDGERS[$key];
        $root = (int) $this->chart->topLevelGroupId($kind);
        if (! $root) {
            $this->fail("The $name account could not be made - the chart of accounts has no $kind section.");
        }
        $parents = Group::pluck('parent_id', 'id')->all();
        $under = function (int $gid) use ($root, $parents) {
            for ($i = 0; $gid > 0 && $i < 20; $i++) {
                if ($gid === $root) {
                    return true;
                }
                $gid = (int) ($parents[$gid] ?? 0);
            }

            return false;
        };
        $found = Ledger::get()->first(fn ($l) => mb_strtolower(trim($l->name)) === mb_strtolower($name) && $under((int) $l->group_id));
        if (! $found) {
            $g = null;
            foreach ($groupPatterns as $p) {
                $g = Group::where('name', 'like', "%$p%")->orderByRaw('LENGTH(code)')->orderBy('code')->get()->first(fn ($x) => $under((int) $x->id));
                if ($g) {
                    break;
                }
            }
            $g ??= Group::find($root);
            $found = Ledger::create(['group_id' => $g->id, 'name' => $name, 'code' => $this->chart->nextLedgerCode((int) $g->id), 'op_balance' => '0.00', 'op_balance_dc' => $dc, 'type' => 0, 'reconciliation' => 0, 'notes' => $note.' (created automatically)']);
            ActivityLog::add('Automatic setup: created the account "'.$name.'" ('.$found->code.')');
        }
        $this->db()->table('commission_settings')->where('id', 1)->update([$col => $found->id]);

        return $found;
    }

    public function label($number): string
    {
        return ($this->settings()->prefix ?: 'COM-').str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /** Bank and cash accounts a person can pick. */
    public function bankOptions(): array
    {
        return Ledger::where('type', 1)->orderBy('name')->get(['id', 'name'])->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name])->all();
    }

    // ---- lists ------------------------------------------------------------------------------------

    public function list(?string $status = null, ?string $q = null): array
    {
        $qb = $this->db()->table('commission_transactions as c')->leftJoin('ledgers as rl', 'rl.id', '=', 'c.received_ledger_id')->leftJoin('ledgers as ml', 'ml.id', '=', 'c.remit_ledger_id')
            ->selectRaw('c.*, rl.name AS received_ledger, ml.name AS remit_ledger');
        if ($status === 'void') {
            $qb->where('c.status', 'void');
        } elseif (in_array($status, ['pending', 'settled'], true)) {
            $qb->where('c.status', $status);
        } else {
            $qb->where('c.status', '!=', 'void');
        }
        if ($q) {
            $qb->where(fn ($w) => $w->where('c.party_name', 'like', "%$q%")->orWhere('c.notes', 'like', "%$q%"));
        }

        return $qb->orderByDesc('c.date')->orderByDesc('c.id')->get()->map(fn ($r) => (array) $r + ['label' => $this->label($r->number)])->all();
    }

    public function find($id): ?array
    {
        $r = $this->db()->table('commission_transactions')->where('id', (int) $id)->first();

        return $r ? (array) $r + ['label' => $this->label($r->number)] : null;
    }

    /** Totals for the pages: owed to clients now, received and commission earned. */
    public function totals(): array
    {
        $rows = $this->db()->table('commission_transactions')->where('status', '!=', 'void')->get(['status', 'received_amount', 'commission_amount', 'remitted_amount']);
        $t = ['pending' => '0.00', 'pending_n' => 0, 'received' => '0.00', 'commission' => '0.00', 'remitted' => '0.00'];
        foreach ($rows as $r) {
            $t['received'] = Money::add($t['received'], $r->received_amount);
            if ($r->status === 'pending') {
                $t['pending'] = Money::add($t['pending'], $r->received_amount);
                $t['pending_n']++;
            } else {
                $t['commission'] = Money::add($t['commission'], $r->commission_amount ?? 0);
                $t['remitted'] = Money::add($t['remitted'], $r->remitted_amount ?? 0);
            }
        }

        return $t;
    }

    /** The Commissions report: every agent with a commission transaction (voided ones excluded). */
    public function summaryByAgent(): array
    {
        $out = [];
        foreach ($this->db()->table('commission_transactions')->where('status', '!=', 'void')->orderBy('party_name')->get() as $r) {
            $k = mb_strtolower(trim($r->party_name));
            $o = &$out[$k];
            $o ??= ['party_name' => trim($r->party_name), 'transaction_count' => 0, 'total_received' => '0.00', 'total_remitted' => '0.00', 'total_commission' => '0.00', 'settled_received' => '0.00', 'outstanding_amount' => '0.00'];
            $o['transaction_count']++;
            $o['total_received'] = Money::add($o['total_received'], $r->received_amount);
            if ($r->status === 'settled') {
                $o['total_remitted'] = Money::add($o['total_remitted'], $r->remitted_amount ?? 0);
                $o['total_commission'] = Money::add($o['total_commission'], $r->commission_amount ?? 0);
                $o['settled_received'] = Money::add($o['settled_received'], $r->received_amount);
            } else {
                $o['outstanding_amount'] = Money::add($o['outstanding_amount'], $r->received_amount);
            }
            unset($o);
        }
        $rows = array_values($out);
        foreach ($rows as &$r) {
            $r['commission_pct'] = Money::positive($r['settled_received']) ? rtrim(rtrim(number_format((float) $r['total_commission'] / (float) $r['settled_received'] * 100, 2, '.', ''), '0'), '.').'%' : '–';
        }

        return $rows;
    }

    // ---- posting ----------------------------------------------------------------------------------

    protected function money($v, string $what): string
    {
        $v = str_replace(',', '', trim((string) $v));
        if ($v === '' || ! is_numeric($v) || (float) $v <= 0) {
            $this->fail("Type $what as a number above 0.");
        }

        return Money::round($v);
    }

    protected function date($v, string $what = 'the date'): string
    {
        $v = trim((string) $v);
        if (! \App\Services\Settings\SettingsService::isDate($v)) {
            $this->fail("Choose $what.");
        }
        if ($e = $this->entries->closedPeriodError($v)) {
            $this->fail($e);
        }

        return $v;
    }

    protected function bank($id, string $what): Ledger
    {
        $l = Ledger::find((int) $id);
        if (! $l || (int) $l->type !== 1) {
            $this->fail("Choose the bank or cash account $what.");
        }

        return $l;
    }

    /** Entry with its lines [ledger id, amount, D|C, narration]; returns its id. */
    protected function post(string $typeLabel, string $date, string $notes, array $lines): int
    {
        $type = $this->db()->table('entrytypes')->where('label', $typeLabel)->first() ?? $this->db()->table('entrytypes')->where('label', 'journal')->first();
        if (! $type) {
            $this->fail('This company has no entry types set up.');
        }
        $dr = $cr = '0.00';
        foreach ($lines as $l) {
            $l[2] === 'D' ? $dr = Money::add($dr, $l[1]) : $cr = Money::add($cr, $l[1]);
        }
        if (Money::cmp($dr, $cr) !== 0) {
            $this->fail('The entry would not balance, so nothing was posted.');
        }
        $id = $this->db()->table('entries')->insertGetId(['entrytype_id' => $type->id, 'tag_id' => null, 'number' => $this->entries->nextNumber((int) $type->id), 'date' => $date, 'dr_total' => $dr, 'cr_total' => $cr, 'notes' => mb_substr($notes, 0, 500)]);
        $rows = [];
        foreach ($lines as $l) {
            $rows[] = ['entry_id' => $id, 'ledger_id' => (int) $l[0], 'amount' => $l[1], 'dc' => $l[2], 'narration' => mb_substr($l[3], 0, 500)];
        }
        $this->db()->table('entryitems')->insert($rows);

        return (int) $id;
    }

    protected function dropEntry($id): void
    {
        if ($id) {
            $this->db()->table('entryitems')->where('entry_id', (int) $id)->delete();
            $this->db()->table('entries')->where('id', (int) $id)->delete();
        }
    }

    /** Money received on a client's behalf. Returns the transaction id. */
    public function receive(array $d): int
    {
        $party = trim((string) ($d['party_name'] ?? ''));
        if ($party === '') {
            $this->fail('Type who the money is for (the client or agent).');
        }
        $date = $this->date($d['date'] ?? '', 'the date it was received');
        $bank = $this->bank($d['received_ledger_id'] ?? 0, 'it was paid into');
        $amount = $this->money($d['received_amount'] ?? '', 'the amount received');
        $set = $this->settings();
        $tax = Money::positive((string) $set->receive_tax_percent) ? Money::percentOf($amount, (string) $set->receive_tax_percent) : '0.00';

        return $this->db()->transaction(function () use ($party, $date, $bank, $amount, $tax, $d) {
            $number = ((int) $this->db()->table('commission_transactions')->max('number')) + 1;
            $label = $this->label($number);
            $due = $this->ledger('due_to_client');
            $lines = [[$bank->id, $amount, 'D', "$label - received from/for $party"], [$due->id, $amount, 'C', "$label - due to $party"]];
            if (Money::positive($tax)) {
                $lines[] = [$this->ledger('tax_expense')->id, $tax, 'D', "$label - tax on money received for $party"];
                $lines[] = [$this->ledger('tax_payable')->id, $tax, 'C', "$label - tax payable on money received for $party"];
            }
            $entry = $this->post('receipt', $date, "$label - funds received for $party", $lines);

            $id = $this->db()->table('commission_transactions')->insertGetId(['number' => $number, 'party_name' => mb_substr($party, 0, 255), 'date' => $date, 'received_ledger_id' => $bank->id, 'received_amount' => $amount,
                'received_entry_id' => $entry, 'received_tax_amount' => Money::positive($tax) ? $tax : null, 'status' => 'pending', 'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 500) ?: null, 'created_at' => now()]);
            ActivityLog::add("Commission $label: received $amount for $party");

            return (int) $id;
        });
    }

    /**
     * Settle a transaction: the company keeps $commission and sends the rest on. $d: settle_date, commission_amount (or commission_percent),
     * remit_ledger_id. The tax on the commission uses the settings' settle tax %.
     */
    public function settle($id, array $d): void
    {
        $t = $this->find($id);
        if (! $t || $t['status'] !== 'pending') {
            $this->fail('That transaction is already settled or was voided.');
        }
        $date = $this->date($d['settle_date'] ?? '', 'the date it was settled');
        if ($date < $t['date']) {
            $this->fail('It cannot be settled before it was received ('.Money::date($t['date']).').');
        }
        $received = (string) $t['received_amount'];
        $commission = trim((string) ($d['commission_amount'] ?? ''));
        if ($commission === '' && trim((string) ($d['commission_percent'] ?? '')) !== '') {
            $commission = Money::percentOf($received, $this->percent($d['commission_percent']));
        }
        $commission = str_replace(',', '', $commission);
        if ($commission === '' || ! is_numeric($commission) || (float) $commission < 0) {
            $this->fail('Type the commission as a number (0 or more) or a percentage.');
        }
        $commission = Money::round($commission);
        if (Money::cmp($commission, $received) > 0) {
            $this->fail('The commission cannot be more than the money received.');
        }
        $remitted = Money::sub($received, $commission);
        $bank = $this->bank($d['remit_ledger_id'] ?? 0, 'the rest is paid from');
        $settleTax = (string) $this->settings()->settle_tax_percent;
        $tax = Money::positive($settleTax) ? Money::percentOf($commission, $settleTax) : '0.00';
        $party = $t['party_name'];
        $label = $t['label'];

        $this->db()->transaction(function () use ($id, $date, $received, $commission, $remitted, $bank, $tax, $party, $label) {
            $lines = [[$this->ledger('due_to_client')->id, $received, 'D', "$label - cleared for $party"]];
            if (Money::positive($commission)) {
                $lines[] = [$this->ledger('commission_income')->id, $commission, 'C', "$label - commission on $party"];
            }
            if (Money::positive($remitted)) {
                $lines[] = [$bank->id, $remitted, 'C', "$label - remitted to $party"];
            }
            if (Money::positive($tax)) {
                $lines[] = [$this->ledger('tax_expense')->id, $tax, 'D', "$label - tax on commission for $party"];
                $lines[] = [$this->ledger('tax_payable')->id, $tax, 'C', "$label - tax payable on commission for $party"];
            }
            $entry = $this->post('payment', $date, "$label - settled for $party", $lines);
            $this->db()->table('commission_transactions')->where('id', (int) $id)->update(['status' => 'settled', 'settle_date' => $date, 'commission_amount' => $commission, 'remitted_amount' => $remitted,
                'settled_tax_amount' => Money::positive($tax) ? $tax : null, 'remit_ledger_id' => $bank->id, 'settled_entry_id' => $entry]);
            ActivityLog::add("Commission $label settled for $party: commission $commission, sent on $remitted");
        });
    }

    /** Takes a settlement back: the settlement entry goes and the money is "due to the client" again. */
    public function unsettle($id): void
    {
        $t = $this->find($id);
        if (! $t || $t['status'] !== 'settled') {
            $this->fail('That transaction is not settled.');
        }
        if ($e = $this->entries->closedPeriodError([$t['settle_date']])) {
            $this->fail($e);
        }
        $this->db()->transaction(function () use ($t) {
            $this->db()->table('commission_transactions')->where('id', (int) $t['id'])->update(['status' => 'pending', 'settle_date' => null, 'commission_amount' => null, 'remitted_amount' => null,
                'settled_tax_amount' => null, 'remit_ledger_id' => null, 'settled_entry_id' => null]);
            $this->dropEntry($t['settled_entry_id']); // after the link to it is gone (foreign key)
            ActivityLog::add("Commission {$t['label']}: settlement taken back");
        });
    }

    /** Voids the whole transaction: both entries are removed and it stays in the list as voided. */
    public function void($id): void
    {
        $t = $this->find($id);
        if (! $t || $t['status'] === 'void') {
            $this->fail('That transaction no longer exists.');
        }
        if ($e = $this->entries->closedPeriodError([$t['date'], $t['settle_date']])) {
            $this->fail($e);
        }
        $this->db()->transaction(function () use ($t) {
            $this->db()->table('commission_transactions')->where('id', (int) $t['id'])->update(['status' => 'void', 'received_entry_id' => null, 'settled_entry_id' => null]);
            $this->dropEntry($t['settled_entry_id']);
            $this->dropEntry($t['received_entry_id']);
            ActivityLog::add("Commission {$t['label']} for {$t['party_name']} voided");
        });
    }
}
