<?php

namespace App\Services\DataSharing;

use App\Models\Tenant\Group;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data Sharing - for a business that buys big data packages on SIM cards and shares them out: a shared SIM has 5 slots, a full SIM
 * has 1 client. Each client has a plan (GB + price) and pays every month (same day). A client who finishes the data early pays again:
 * a recharge, at the plan price, in the same cycle. Ported from the legacy Datasharing_model.
 *
 * The business works in monthly cycles (1st to the last day of the month). Every payment and expense belongs to the open cycle;
 * closing the cycle saves its numbers (the presentation), locks it and opens the next one, adding the SIM packages for it.
 *
 * Books (every payment / expense is a real entry):
 *   plan payment   Dr Bank / Shop2Shop / Kazang / Cash   Cr Data Sharing Income
 *   recharge       Dr method                             Cr Data Recharge Income
 *   SIM package    Dr Data Packages                      Cr method
 *   extra data     Dr Extra Data                         Cr method
 *   other spending Dr Transport / Airtime / ...          Cr method
 * Voiding removes the entry. The accounts are found by name or made once (and remembered in app_flags as ds_<key>_ledger_id).
 *
 * Methods return null when it worked and an error sentence when it did not (the pages show it), as the legacy model did.
 */
class DataSharingService
{
    public const METHODS = ['Bank', 'Shop2Shop', 'Kazang', 'Cash'];

    /** Money Out category => account key */
    public const CATS = ['Data packages' => 'packages', 'Extra data' => 'extra', 'Transport' => 'transport', 'Airtime & phone' => 'airtime', 'Rent' => 'rent', 'Staff' => 'staff', 'Other' => 'other'];

    /** Money In that is not a client payment: category => account key; only the first two are income (P&L) */
    public const CATS_IN = ['Other income' => 'other_income', 'SIM card sales' => 'sim_sales', 'Owner money (capital)' => 'capital', 'Loan received' => 'loan'];

    public const INCOME_IN = ['Other income', 'SIM card sales'];

    public const DEFAULT_PLANS = [[40, 200, 0], [50, 250, 0], [70, 330, 0], [100, 450, 0], [200, 700, 1]];

    /** The accounts: key => root kind, group patterns (first that exists wins), name, normal side, note. */
    protected const LEDGERS = [
        'income' => ['income', ['operating income', 'sales', 'income'], 'Data Sharing Income', 'C', 'Money from clients\' monthly data plans'],
        'recharge' => ['income', ['operating income', 'sales', 'income'], 'Data Recharge Income', 'C', 'Money from clients who recharged before the month ended'],
        'other_income' => ['income', ['other income', 'income'], 'Other Data Income', 'C', 'Data Sharing: other income'],
        'sim_sales' => ['income', ['other income', 'operating income', 'income'], 'SIM Card Sales', 'C', 'Data Sharing: SIM cards sold'],
        'packages' => ['expense', ['cost of sales', 'cost of goods', 'direct cost', 'expense'], 'Data Packages', 'D', 'What the SIM packages cost each month'],
        'extra' => ['expense', ['cost of sales', 'cost of goods', 'direct cost', 'expense'], 'Extra Data', 'D', 'Extra data bought for clients who recharged'],
        'transport' => ['expense', ['operating expenses', 'operating', 'expense'], 'Data Transport', 'D', 'Data Sharing: transport'],
        'airtime' => ['expense', ['operating expenses', 'operating', 'expense'], 'Airtime and Phone', 'D', 'Data Sharing: airtime and phone'],
        'rent' => ['expense', ['operating expenses', 'operating', 'expense'], 'Data Rent', 'D', 'Data Sharing: rent'],
        'staff' => ['expense', ['payroll expenses', 'payroll', 'operating expenses', 'expense'], 'Data Staff Costs', 'D', 'Data Sharing: staff'],
        'other' => ['expense', ['other expenses', 'operating expenses', 'expense'], 'Data Other Expenses', 'D', 'Data Sharing: other spending'],
        'capital' => ['equity', ['equity', 'capital', 'owner'], 'Owner Capital (Data)', 'C', 'Money the owner put in'],
        'loan' => ['liability', ['loans', 'long-term', 'current liab'], 'Loans Received (Data)', 'C', 'Loans received'],
    ];

    /** id of the last payment or recharge saved (for the WhatsApp receipt) */
    public ?int $lastPaymentId = null;

    public function __construct(protected ChartOfAccounts $chart, protected EntryService $entries) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    // ---- is it on? --------------------------------------------------------------------------------

    public static function enabled(): bool
    {
        return Tables::has('settings', 'datasharing_enabled') && (int) (Setting::first()->datasharing_enabled ?? 0) !== 0;
    }

    /** On, and non-admin users see only the Data Sharing pages. */
    public static function only(): bool
    {
        return self::enabled() && Tables::has('settings', 'datasharing_only') && ! empty(Setting::first()->datasharing_only);
    }

    /** The two settings columns, made on first use (never inside a transaction). */
    public static function ensureSettingsColumns(): void
    {
        $schema = Schema::connection('tenant');
        foreach (['datasharing_enabled', 'datasharing_only'] as $col) {
            if (! $schema->hasColumn('settings', $col)) {
                DB::connection('tenant')->statement("ALTER TABLE `settings` ADD COLUMN `$col` TINYINT(1) NOT NULL DEFAULT 0");
            }
        }
    }

    // ---- schema -----------------------------------------------------------------------------------

    /** Makes the Data Sharing tables (and later columns) when missing. Never call inside a transaction. */
    public function ensureSchema(): bool
    {
        static $done = [];
        $key = config('database.connections.tenant.database');
        if (isset($done[$key])) {
            return $done[$key];
        }
        try {
            $q = fn (string $sql) => $this->db()->statement($sql);
            $q('CREATE TABLE IF NOT EXISTS `ds_plans` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `gb` INT NOT NULL, `price` DECIMAL(25,2) NOT NULL DEFAULT 0, `full` TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (`id`)) DEFAULT CHARSET=utf8');
            $q("CREATE TABLE IF NOT EXISTS `ds_cycles` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `no` INT NOT NULL, `start` DATE NOT NULL, `end` DATE NOT NULL, `status` VARCHAR(10) NOT NULL DEFAULT 'open', `closed_on` DATE NULL, `closed_by` VARCHAR(150) NULL, `snapshot` LONGTEXT NULL, PRIMARY KEY (`id`)) DEFAULT CHARSET=utf8");
            $q("CREATE TABLE IF NOT EXISTS `ds_sims` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `number` VARCHAR(40) NOT NULL, `kind` VARCHAR(10) NOT NULL DEFAULT 'shared', `gb` INT NOT NULL DEFAULT 200, `cost` DECIMAL(25,2) NOT NULL DEFAULT 0, `method` VARCHAR(20) NOT NULL DEFAULT 'Bank', `status` VARCHAR(10) NOT NULL DEFAULT 'active', `notes` VARCHAR(255) NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`)) DEFAULT CHARSET=utf8");
            $q("CREATE TABLE IF NOT EXISTS `ds_clients` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `sim_id` INT UNSIGNED NOT NULL, `name` VARCHAR(150) NOT NULL, `phone` VARCHAR(40) NULL, `gb` INT NOT NULL, `price` DECIMAL(25,2) NOT NULL DEFAULT 0, `next_due` DATE NOT NULL, `status` VARCHAR(10) NOT NULL DEFAULT 'active', `notes` VARCHAR(255) NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `sim` (`sim_id`)) DEFAULT CHARSET=utf8");
            $q("CREATE TABLE IF NOT EXISTS `ds_payments` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `cycle_id` INT UNSIGNED NOT NULL, `client_id` INT UNSIGNED NOT NULL, `sim_id` INT UNSIGNED NOT NULL, `kind` VARCHAR(10) NOT NULL DEFAULT 'plan', `gb` INT NOT NULL DEFAULT 0, `months` INT NOT NULL DEFAULT 1, `amount` DECIMAL(25,2) NOT NULL, `date` DATE NOT NULL, `method` VARCHAR(20) NOT NULL, `src` VARCHAR(10) NULL, `note` VARCHAR(255) NULL, `entry_id` BIGINT NULL, `void` TINYINT(1) NOT NULL DEFAULT 0, `created_by` VARCHAR(150) NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `cyc` (`cycle_id`), KEY `cli` (`client_id`)) DEFAULT CHARSET=utf8");
            $q("CREATE TABLE IF NOT EXISTS `ds_expenses` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `cycle_id` INT UNSIGNED NOT NULL, `date` DATE NOT NULL, `cat` VARCHAR(30) NOT NULL, `text` VARCHAR(255) NULL, `amount` DECIMAL(25,2) NOT NULL, `method` VARCHAR(20) NOT NULL, `sim_id` INT UNSIGNED NULL, `client_id` INT UNSIGNED NULL, `auto` TINYINT(1) NOT NULL DEFAULT 0, `entry_id` BIGINT NULL, `void` TINYINT(1) NOT NULL DEFAULT 0, `created_by` VARCHAR(150) NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `cyc` (`cycle_id`)) DEFAULT CHARSET=utf8");
            $q('CREATE TABLE IF NOT EXISTS `ds_moves` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `cycle_id` INT UNSIGNED NOT NULL, `date` DATE NOT NULL, `client_id` INT UNSIGNED NOT NULL, `name` VARCHAR(150) NULL, `from_sim` VARCHAR(40) NULL, `to_sim` VARCHAR(40) NULL, `why` VARCHAR(20) NULL, PRIMARY KEY (`id`)) DEFAULT CHARSET=utf8');
            $q('CREATE TABLE IF NOT EXISTS `ds_notes` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `text` VARCHAR(500) NOT NULL, `done` TINYINT(1) NOT NULL DEFAULT 0, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`)) DEFAULT CHARSET=utf8');
            $q('CREATE TABLE IF NOT EXISTS `ds_income` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `cycle_id` INT UNSIGNED NOT NULL, `date` DATE NOT NULL, `cat` VARCHAR(30) NOT NULL, `text` VARCHAR(255) NULL, `amount` DECIMAL(25,2) NOT NULL, `method` VARCHAR(20) NOT NULL, `entry_id` BIGINT NULL, `void` TINYINT(1) NOT NULL DEFAULT 0, `created_by` VARCHAR(150) NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `cyc` (`cycle_id`)) DEFAULT CHARSET=utf8');
            $q('CREATE TABLE IF NOT EXISTS `ds_usage` (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `client_id` INT UNSIGNED NOT NULL, `sim_id` INT UNSIGNED NOT NULL, `date` DATE NOT NULL, `used` DECIMAL(10,2) NOT NULL DEFAULT 0, `of_gb` INT NOT NULL DEFAULT 0, `created_by` VARCHAR(150) NULL, `created_at` DATETIME NOT NULL, PRIMARY KEY (`id`), KEY `cli` (`client_id`)) DEFAULT CHARSET=utf8');
            $add = function (string $table, string $col, string $ddl) use ($q) {
                if (! Schema::connection('tenant')->hasColumn($table, $col)) {
                    $q("ALTER TABLE `$table` ADD COLUMN $ddl");
                }
            };
            $add('ds_clients', 'whatsapp', '`whatsapp` VARCHAR(40) NULL AFTER `phone`');
            $add('ds_plans', 'name', '`name` VARCHAR(60) NULL AFTER `id`');
            $add('ds_plans', 'active', '`active` TINYINT(1) NOT NULL DEFAULT 1');
            $add('ds_plans', 'notes', '`notes` VARCHAR(255) NULL');
            if (! Schema::connection('tenant')->hasColumn('ds_sims', 'contact_client_id')) {
                $q('ALTER TABLE `ds_sims` ADD COLUMN `contact_client_id` INT UNSIGNED NULL, ADD COLUMN `contact_name` VARCHAR(150) NULL, ADD COLUMN `contact_phone` VARCHAR(40) NULL, ADD COLUMN `contact_whatsapp` VARCHAR(40) NULL');
            }
            $add('ds_clients', 'credit', '`credit` DECIMAL(25,2) NOT NULL DEFAULT 0');
            $add('ds_payments', 'plan_price', '`plan_price` DECIMAL(25,2) NULL');
            $add('ds_sims', 'pkg_until', '`pkg_until` DATE NULL');
            $add('ds_clients', 'due_day', '`due_day` TINYINT UNSIGNED NULL AFTER `next_due`');
            if (! Schema::connection('tenant')->hasTable('app_flags')) {
                Schema::connection('tenant')->create('app_flags', function ($t) {
                    $t->string('name', 64)->primary();
                    $t->string('value', 255)->nullable();
                });
            }
            if ($this->db()->table('ds_plans')->count() == 0) {
                foreach (self::DEFAULT_PLANS as $p) {
                    $this->db()->table('ds_plans')->insert(['gb' => $p[0], 'price' => $p[1], 'full' => $p[2], 'active' => 1]);
                }
            }

            return $done[$key] = true;
        } catch (\Throwable $e) {
            report($e);

            return $done[$key] = false;
        }
    }

    // ---- small helpers ----------------------------------------------------------------------------

    public function today(): string
    {
        return date('Y-m-d');
    }

    public function addDays(string $date, int $n): string
    {
        return date('Y-m-d', strtotime($date.' '.($n >= 0 ? '+' : '').$n.' days'));
    }

    /** Last day of $date's month. */
    public function monthEnd(string $date): string
    {
        return date('Y-m-t', strtotime($date));
    }

    /** $date moved by $n months, kept on day $day (or $date's day); a short month uses its last day (31 Jan -> 28 Feb -> 31 Mar). */
    public function addMonths(string $date, int $n, ?int $day = null): string
    {
        $t = strtotime($date);
        $y = (int) date('Y', $t);
        $mo = (int) date('n', $t) + $n;
        while ($mo > 12) {
            $mo -= 12;
            $y++;
        }
        while ($mo < 1) {
            $mo += 12;
            $y--;
        }
        $day = $day ?: (int) date('j', $t);
        $last = (int) date('t', mktime(0, 0, 0, $mo, 1, $y));

        return sprintf('%04d-%02d-%02d', $y, $mo, min($day, $last));
    }

    public function daysFromToday(string $date): int
    {
        return (int) round((strtotime($date) - strtotime($this->today())) / 86400);
    }

    /** "1,250.5" -> "1250.50"; '' or a bad number -> null. */
    public function moneyIn($v): ?string
    {
        $v = str_replace([',', ' '], '', (string) $v);
        $v = preg_replace('/^[^0-9.\-]+/', '', $v);
        if ($v === '' || ! is_numeric($v) || (float) $v <= 0) {
            return null;
        }

        return number_format((float) $v, 2, '.', '');
    }

    public function validDate($d): ?string
    {
        $d = trim((string) $d);
        if ($d === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $t = strtotime($d);
            $d = $t ? date('Y-m-d', $t) : '';
        }

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $d : null;
    }

    protected function who(): string
    {
        $u = Auth::user();

        return $u ? (trim($u->first_name.' '.$u->last_name) ?: (string) $u->username) : '';
    }

    public function methodOk($m): bool
    {
        return in_array($m, self::METHODS, true);
    }

    public function arr($row): ?array
    {
        return $row ? (array) $row : null;
    }

    protected function rows($q): array
    {
        return $q->get()->map(fn ($r) => (array) $r)->all();
    }

    protected function f($v): float
    {
        return (float) $v;
    }

    // ---- accounts and entries ---------------------------------------------------------------------

    protected function flag(string $name): ?string
    {
        return $this->db()->table('app_flags')->where('name', $name)->value('value');
    }

    protected function setFlag(string $name, string $value): void
    {
        $this->db()->statement('INSERT INTO app_flags (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$name, $value]);
    }

    protected function groupUnder(int $groupId, int $rootId, array $parents): bool
    {
        for ($i = 0; $groupId > 0 && $i < 20; $i++) {
            if ($groupId === $rootId) {
                return true;
            }
            $groupId = (int) ($parents[$groupId] ?? 0);
        }

        return false;
    }

    /** The root group id for a kind, including 'equity' (the Equity group under Liabilities, else the Liabilities root). */
    protected function rootFor(string $kind): ?int
    {
        if ($kind === 'equity') {
            $g = Group::whereNotNull('parent_id')->where('name', 'like', '%equity%')->orderByRaw('LENGTH(code)')->first();

            return $g ? (int) $g->id : $this->chart->topLevelGroupId('liability');
        }

        return $this->chart->topLevelGroupId($kind);
    }

    /**
     * An account: key 'income', 'packages' ... or 'method:Bank'. The remembered one, else an existing account with the right name
     * (under the right section of the chart), else a new one.
     */
    public function ledger(string $key): ?Ledger
    {
        $flag = 'ds_'.strtolower(str_replace(':', '_', $key)).'_ledger_id';
        if (($id = (int) $this->flag($flag)) > 0 && ($l = Ledger::find($id))) {
            return $l;
        }
        if (str_starts_with($key, 'method:')) {
            $name = substr($key, 7);
            $sp = ['asset', ['cash and bank', 'cash', 'bank', 'current assets'], $name, 'D', "Data Sharing: money received or paid by $name"];
        } else {
            $sp = self::LEDGERS[$key] ?? null;
            if (! $sp) {
                return null;
            }
        }
        [$kind, $groupPatterns, $name, $dc, $note] = $sp;
        $root = (int) $this->rootFor($kind);
        if (! $root) {
            return null;
        }
        $parents = Group::pluck('parent_id', 'id')->all();
        $found = Ledger::get()->first(fn ($l) => mb_strtolower(trim($l->name)) === mb_strtolower($name) && $this->groupUnder((int) $l->group_id, $root, $parents));
        if ($found) {
            $this->setFlag($flag, (string) $found->id);

            return $found;
        }
        $g = null;
        foreach ($groupPatterns as $p) {
            $g = Group::where('name', 'like', "%$p%")->orderByRaw('LENGTH(code)')->orderBy('code')->get()->first(fn ($x) => $this->groupUnder((int) $x->id, $root, $parents));
            if ($g) {
                break;
            }
        }
        $g ??= Group::find($root);
        $isBank = str_starts_with($key, 'method:') ? 1 : 0; // a bank / cash account
        $l = Ledger::create(['group_id' => $g->id, 'name' => $name, 'code' => $this->chart->nextLedgerCode((int) $g->id), 'op_balance' => '0.00', 'op_balance_dc' => $dc,
            'type' => $isBank, 'reconciliation' => 0, 'notes' => $note.' (created automatically)']);
        $this->setFlag($flag, (string) $l->id);
        ActivityLog::add('Automatic setup: created the account "'.$name.'" ('.$l->code.')');

        return $l;
    }

    /** One balanced two-line entry; returns the entry id or null. */
    protected function post(string $etLabel, string $date, string $notes, ?Ledger $dr, ?Ledger $cr, string $amount, string $narration): ?int
    {
        if (! $dr || ! $cr) {
            return null;
        }
        $et = $this->db()->table('entrytypes')->where('label', $etLabel)->value('id') ?: $this->db()->table('entrytypes')->where('label', 'journal')->value('id');
        if (! $et) {
            return null;
        }
        $id = $this->db()->table('entries')->insertGetId(['entrytype_id' => $et, 'tag_id' => null, 'number' => $this->entries->nextNumber((int) $et), 'date' => $date, 'dr_total' => $amount, 'cr_total' => $amount, 'notes' => mb_substr($notes, 0, 500)]);
        $this->db()->table('entryitems')->insert([
            ['entry_id' => $id, 'ledger_id' => (int) $dr->id, 'amount' => $amount, 'dc' => 'D', 'narration' => mb_substr($narration, 0, 255)],
            ['entry_id' => $id, 'ledger_id' => (int) $cr->id, 'amount' => $amount, 'dc' => 'C', 'narration' => mb_substr($narration, 0, 255)],
        ]);

        return (int) $id;
    }

    protected function dropEntry($entryId): void
    {
        if (empty($entryId)) {
            return;
        }
        $this->db()->table('entryitems')->where('entry_id', (int) $entryId)->delete();
        $this->db()->table('entries')->where('id', (int) $entryId)->delete();
    }

    protected function locked(string $date): ?string
    {
        return $this->entries->closedPeriodError($date);
    }

    // ---- plans ------------------------------------------------------------------------------------

    /** All plans (or only active ones), slot plans first, then by GB. */
    public function plans(bool $activeOnly = false): array
    {
        $q = $this->db()->table('ds_plans');
        if ($activeOnly) {
            $q->where('active', 1);
        }

        return $this->rows($q->orderBy('full')->orderBy('gb'));
    }

    public function plan($id): ?array
    {
        return $this->arr($this->db()->table('ds_plans')->where('id', (int) $id)->first());
    }

    public function planPrice($gb, ?string $kind = null): ?string
    {
        $q = $this->db()->table('ds_plans')->where('gb', (int) $gb);
        if ($kind !== null) {
            $q->where('full', $kind === 'full' ? 1 : 0);
        }
        $p = $q->orderByDesc('active')->first();

        return $p ? (string) $p->price : null;
    }

    /** Plans that can be picked for a SIM type: the active ones, plus the plan with GB $keepGb (a client's current plan). */
    public function plansFor(string $kind, $keepGb = null): array
    {
        return array_values(array_filter($this->plans(), fn ($p) => ((int) $p['full'] === 1) === ($kind === 'full') && ((int) $p['active'] === 1 || ($keepGb !== null && (int) $p['gb'] === (int) $keepGb))));
    }

    /** "Gold (70 GB)" / "70 GB" / "200 GB full package". */
    public function planLabel(array $p): string
    {
        $base = (int) $p['gb'].' GB'.((int) $p['full'] ? ' full package' : '');

        return ! empty($p['name']) ? $p['name'].' ('.$base.')' : $base;
    }

    public function planName($gb): string
    {
        $p = $this->db()->table('ds_plans')->where('gb', (int) $gb)->orderByDesc('active')->first();

        return $p ? $this->planLabel((array) $p) : (int) $gb.' GB';
    }

    /** Clients on a plan (same GB, on SIMs of the plan's type). */
    public function planClients(array $p): array
    {
        return $this->rows($this->db()->table('ds_clients as c')->join('ds_sims as s', 's.id', '=', 'c.sim_id')->select('c.*')
            ->where('c.status', 'active')->where('c.gb', (int) $p['gb'])->where('s.kind', (int) $p['full'] ? 'full' : 'shared'));
    }

    protected function planInput(array $d, ?int $id = null): array
    {
        $full = ! empty($d['full']) ? 1 : 0;
        $gb = (int) preg_replace('/[^0-9]/', '', (string) ($d['gb'] ?? ''));
        if ($gb < 1 || $gb > 2000) {
            return [null, 'Type the GB (1 to 2000).'];
        }
        $price = $this->moneyIn($d['price'] ?? '');
        if ($price === null) {
            return [null, 'Type the price.'];
        }
        $q = $this->db()->table('ds_plans')->where('gb', $gb)->where('full', $full);
        if ($id) {
            $q->where('id', '!=', $id);
        }
        if ($q->exists()) {
            return [null, 'There is already a '.$gb.' GB '.($full ? 'full SIM' : 'slot').' plan. Change that one instead.'];
        }

        return [['name' => mb_substr(trim((string) ($d['name'] ?? '')), 0, 60) ?: null, 'gb' => $gb, 'price' => $price, 'full' => $full, 'notes' => mb_substr(trim((string) ($d['notes'] ?? '')), 0, 255) ?: null], null];
    }

    public function addPlan(array $d): ?string
    {
        [$row, $err] = $this->planInput($d);
        if ($err) {
            return $err;
        }
        $row['active'] = 1;
        $this->db()->table('ds_plans')->insert($row);

        return null;
    }

    /** Change a plan. Its GB / type can't change while clients are on it. $apply = also give the new price to its clients who pay the old price. */
    public function updatePlan($id, array $d, bool $apply): ?string
    {
        $p = $this->plan($id);
        if (! $p) {
            return 'That plan no longer exists.';
        }
        [$row, $err] = $this->planInput($d, (int) $id);
        if ($err) {
            return $err;
        }
        $on = $this->planClients($p);
        if ($on && ((int) $row['gb'] !== (int) $p['gb'] || (int) $row['full'] !== (int) $p['full'])) {
            return count($on).' client'.(count($on) > 1 ? 's are' : ' is').' on this plan, so its GB and type stay. Add a new plan and move them to it instead.';
        }
        if ($apply && Money::cmp($p['price'], $row['price']) !== 0) {
            foreach ($on as $c) {
                if (Money::cmp($c['price'], $p['price']) === 0) {
                    $this->db()->table('ds_clients')->where('id', (int) $c['id'])->update(['price' => $row['price']]);
                }
            }
        }
        $this->db()->table('ds_plans')->where('id', (int) $id)->update($row);

        return null;
    }

    /** Hide / show a plan for new clients. */
    public function togglePlan($id): ?string
    {
        $p = $this->plan($id);
        if (! $p) {
            return 'That plan no longer exists.';
        }
        if ((int) $p['active'] && count($this->plansFor((int) $p['full'] ? 'full' : 'shared')) < 2) {
            return 'Keep at least one '.((int) $p['full'] ? 'full SIM' : 'slot').' plan in use.';
        }
        $this->db()->table('ds_plans')->where('id', (int) $id)->update(['active' => (int) $p['active'] ? 0 : 1]);

        return null;
    }

    public function deletePlan($id): ?string
    {
        $p = $this->plan($id);
        if (! $p) {
            return 'That plan no longer exists.';
        }
        if ($this->planClients($p)) {
            return 'Clients are on this plan. Move them to another plan first, or hide it instead.';
        }
        if ((int) $p['active'] && count($this->plansFor((int) $p['full'] ? 'full' : 'shared')) < 2) {
            return 'Keep at least one '.((int) $p['full'] ? 'full SIM' : 'slot').' plan.';
        }
        $this->db()->table('ds_plans')->where('id', (int) $id)->delete();

        return null;
    }

    /** Move clients from one plan to another (upgrade / downgrade). @return array{0: array, 1: array, 2: ?string} moved names, skipped names, error */
    public function movePlanClients($fromId, $toId, ?array $ids = null): array
    {
        $from = $this->plan($fromId);
        $to = $this->plan($toId);
        if (! $from || ! $to) {
            return [[], [], 'Choose the plan to move them to.'];
        }
        if ((int) $from['full'] !== (int) $to['full']) {
            return [[], [], 'A slot plan and a full SIM plan can\'t be swapped.'];
        }
        if ((int) $from['id'] === (int) $to['id']) {
            return [[], [], 'Choose a different plan.'];
        }
        $moved = $skipped = [];
        foreach ($this->planClients($from) as $c) {
            if (is_array($ids) && ! in_array((int) $c['id'], array_map('intval', $ids), true)) {
                continue;
            }
            $s = $this->sim($c['sim_id']);
            if ((int) $to['gb'] - (int) $c['gb'] > $this->spareGb($s)) {
                $skipped[] = $c['name'].' ('.$s['number'].' has only '.max(0, $this->spareGb($s)).' GB spare)';

                continue;
            }
            $this->db()->table('ds_clients')->where('id', (int) $c['id'])->update(['gb' => (int) $to['gb'], 'price' => $to['price']]);
            $moved[] = $c['name'];
            $this->addNote('Change '.$c['name'].' ('.$c['phone'].') on '.$s['number'].' from '.$c['gb'].' GB to '.$to['gb'].' GB.');
        }

        return [$moved, $skipped, null];
    }

    // ---- cycles -----------------------------------------------------------------------------------

    /** The open cycle; the very first one starts today. */
    public function openCycle(): array
    {
        $c = $this->arr($this->db()->table('ds_cycles')->where('status', 'open')->orderByDesc('id')->first());
        if ($c) {
            if ($c['end'] !== $this->monthEnd($c['start'])) { // monthly cycles: a cycle ends on the last day of the month it started in
                $c['end'] = $this->monthEnd($c['start']);
                $this->db()->table('ds_cycles')->where('id', (int) $c['id'])->update(['end' => $c['end']]);
            }

            return $c;
        }
        $last = $this->arr($this->db()->table('ds_cycles')->orderByDesc('no')->first());
        $start = $last ? $this->addDays($last['end'], 1) : $this->today();
        $id = $this->db()->table('ds_cycles')->insertGetId(['no' => $last ? (int) $last['no'] + 1 : 1, 'start' => $start, 'end' => $this->monthEnd($start), 'status' => 'open']);

        return (array) $this->db()->table('ds_cycles')->where('id', $id)->first();
    }

    public function cycle($id): ?array
    {
        return $this->arr($this->db()->table('ds_cycles')->where('id', (int) $id)->first());
    }

    public function closedCycles(): array
    {
        return $this->rows($this->db()->table('ds_cycles')->where('status', 'closed')->orderByDesc('no'));
    }

    public function allCycles(): array
    {
        return $this->rows($this->db()->table('ds_cycles')->orderByDesc('no'));
    }

    public function cycleDays(array $c): int
    {
        return (int) round((strtotime($c['end']) - strtotime($c['start'])) / 86400) + 1;
    }

    public function dayOfCycle(array $c): int
    {
        return max(1, min($this->cycleDays($c), (int) floor((strtotime($this->today()) - strtotime($c['start'])) / 86400) + 1));
    }

    /** Change the open cycle's first day (only before anything was closed). */
    public function setCycleStart($date): ?string
    {
        $date = $this->validDate($date);
        if (! $date) {
            return 'Choose a real date.';
        }
        if ($this->db()->table('ds_cycles')->where('status', 'closed')->exists()) {
            return 'The start can only be changed before the first cycle is closed.';
        }
        $c = $this->openCycle();
        if ($date > $this->today()) {
            return 'The cycle cannot start in the future.';
        }
        $this->db()->table('ds_cycles')->where('id', (int) $c['id'])->update(['start' => $date, 'end' => $this->monthEnd($date)]);

        return null;
    }

    // ---- SIMs and clients -------------------------------------------------------------------------

    public function sims(bool $all = false): array
    {
        $q = $this->db()->table('ds_sims');
        if (! $all) {
            $q->where('status', 'active');
        }

        return $this->rows($q->orderBy('id'));
    }

    public function sim($id): ?array
    {
        return $this->arr($this->db()->table('ds_sims')->where('id', (int) $id)->first());
    }

    public function slots(array $s): int
    {
        return $s['kind'] === 'full' ? 1 : 5;
    }

    public function clients($simId = null): array
    {
        $q = $this->db()->table('ds_clients')->where('status', 'active');
        if ($simId !== null) {
            $q->where('sim_id', (int) $simId);
        }

        return $this->rows($q->orderBy('id'));
    }

    /** The day of the month the client pays on (kept so 31st stays 31st after a short month). */
    public function dueDay(array $c): int
    {
        return ! empty($c['due_day']) ? (int) $c['due_day'] : (int) date('j', strtotime($c['next_due']));
    }

    public function client($id): ?array
    {
        return $this->arr($this->db()->table('ds_clients')->where('id', (int) $id)->first());
    }

    public function usedGb($simId): int
    {
        return (int) $this->db()->table('ds_clients')->where('sim_id', (int) $simId)->where('status', 'active')->sum('gb');
    }

    public function freeSlots(array $s): int
    {
        return $this->slots($s) - $this->db()->table('ds_clients')->where('sim_id', (int) $s['id'])->where('status', 'active')->count();
    }

    /** GB not given out on the SIM, less recharges taken from it this cycle. */
    public function spareGb(array $s, $cycleId = null): int
    {
        $cycleId = $cycleId ?: (int) $this->openCycle()['id'];
        $rc = (int) $this->db()->table('ds_payments')->where('cycle_id', (int) $cycleId)->where('sim_id', (int) $s['id'])->where('kind', 'recharge')->where('src', 'spare')->where('void', 0)->sum('gb');

        return (int) $s['gb'] - $this->usedGb($s['id']) - $rc;
    }

    public function payments($cycleId, $clientId = null): array
    {
        $q = $this->db()->table('ds_payments')->where('cycle_id', (int) $cycleId)->where('void', 0);
        if ($clientId) {
            $q->where('client_id', (int) $clientId);
        }

        return $this->rows($q->orderBy('date')->orderBy('id'));
    }

    public function expenses($cycleId): array
    {
        return $this->rows($this->db()->table('ds_expenses')->where('cycle_id', (int) $cycleId)->where('void', 0)->orderByDesc('date')->orderByDesc('id'));
    }

    /** 'paid' | 'unpaid' | 'overdue' for the open cycle. */
    public function status(array $c, $cycleId): string
    {
        if ($c['next_due'] < $this->today()) {
            return 'overdue';
        }
        // a part payment (months = 0) doesn't make a client paid; a client paid ahead past this cycle is paid
        $n = $this->db()->table('ds_payments')->where('cycle_id', (int) $cycleId)->where('client_id', (int) $c['id'])->where('kind', 'plan')->where('months', '>', 0)->where('void', 0)->count();
        if ($n) {
            return 'paid';
        }
        $cyc = $this->cycle($cycleId);
        if ($cyc && $c['next_due'] > $cyc['end'] && $this->db()->table('ds_payments')->where('client_id', (int) $c['id'])->where('kind', 'plan')->where('months', '>', 0)->where('void', 0)->exists()) {
            return 'paid';
        }

        return 'unpaid';
    }

    /** What the client still owes for their next month (price less any part payment). */
    public function owed(array $c): float
    {
        return max(0, round((float) $c['price'] - (float) ($c['credit'] ?? 0), 2));
    }

    // ---- data used / left ------------------------------------------------------------------------
    // MTN doesn't share usage with outside apps, so the business checks MTN and types a reading: GB used (or GB left) of the client's
    // plan. A reading counts until the client's next payment or recharge (their data starts again then).

    /** client_id => [used, left, of_gb, date, id] for readings still current. */
    public function usageMap(): array
    {
        $last = $this->db()->select('SELECT u.* FROM ds_usage u JOIN (SELECT client_id, MAX(id) mid FROM ds_usage GROUP BY client_id) x ON u.id = x.mid');
        $paid = [];
        foreach ($this->db()->select('SELECT client_id, MAX(created_at) AS at FROM ds_payments WHERE void = 0 GROUP BY client_id') as $r) {
            $paid[$r->client_id] = $r->at;
        }
        $out = [];
        foreach ($last as $u) {
            if (isset($paid[$u->client_id]) && $paid[$u->client_id] > $u->created_at) {
                continue;
            }
            $used = round((float) $u->used, 2);
            $of = (int) $u->of_gb;
            $out[(int) $u->client_id] = ['used' => $used, 'left' => max(0, round($of - $used, 2)), 'of_gb' => $of, 'date' => $u->date, 'id' => (int) $u->id];
        }

        return $out;
    }

    /** $d: value (GB), mode 'used' | 'left', date. */
    public function addUsage($clientId, array $d): ?string
    {
        $c = $this->client($clientId);
        if (! $c || $c['status'] !== 'active') {
            return 'That client no longer exists.';
        }
        $raw = str_replace([',', ' ', 'GB', 'gb'], ['.', '', '', ''], trim((string) ($d['value'] ?? '')));
        if ($raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw < 0) {
            return 'Type the GB for '.$c['name'].' as a number, e.g. 12.5.';
        }
        $gb = (int) $c['gb'];
        $v = round((float) $raw, 2);
        if ($v > $gb) {
            return $c['name'].' has a '.$gb.' GB plan; the reading can\'t be more than '.$gb.' GB.';
        }
        $used = (($d['mode'] ?? '') === 'left') ? $gb - $v : $v;
        $date = $this->validDate($d['date'] ?? '') ?: $this->today();
        if ($date > $this->today()) {
            $date = $this->today();
        }
        $this->db()->table('ds_usage')->insert(['client_id' => (int) $c['id'], 'sim_id' => (int) $c['sim_id'], 'date' => $date, 'used' => $used, 'of_gb' => $gb, 'created_by' => $this->who(), 'created_at' => date('Y-m-d H:i:s')]);

        return null;
    }

    /** Readings for every client on a SIM at once; $vals = client_id => GB. */
    public function addUsageSim($simId, array $vals, $mode, $date): ?string
    {
        $s = $this->sim($simId);
        if (! $s) {
            return 'That SIM no longer exists.';
        }
        $errs = [];
        $n = 0;
        foreach ($this->clients($simId) as $c) {
            if (! isset($vals[$c['id']]) || trim((string) $vals[$c['id']]) === '') {
                continue;
            }
            $err = $this->addUsage($c['id'], ['value' => $vals[$c['id']], 'mode' => $mode, 'date' => $date]);
            $err ? $errs[] = $err : $n++;
        }
        if ($errs) {
            return implode(' ', $errs).($n ? ' The other readings were saved.' : '');
        }

        return $n ? null : 'Type at least one reading.';
    }

    public function usageHistory($clientId, int $limit = 15): array
    {
        return $this->rows($this->db()->table('ds_usage')->where('client_id', (int) $clientId)->orderByDesc('id')->limit($limit));
    }

    /** @return array{0: ?int, 1: ?string} client id, error */
    public function deleteUsage($id): array
    {
        $u = $this->arr($this->db()->table('ds_usage')->where('id', (int) $id)->first());
        if (! $u) {
            return [null, 'That reading no longer exists.'];
        }
        $this->db()->table('ds_usage')->where('id', (int) $id)->delete();

        return [(int) $u['client_id'], null];
    }

    // ---- main contact ----------------------------------------------------------------------------
    // A shared SIM can have one main contact: the person responsible for the whole package. Usually one of the clients on the SIM
    // (contact_client_id), sometimes someone else (contact_name / phone / whatsapp). Each client still pays for their own slot.

    /** The SIM's main contact or null: name, phone, whatsapp, client_id (0 = not on this SIM). */
    public function simContact(array $s): ?array
    {
        if (! empty($s['contact_client_id'])) {
            $c = $this->client($s['contact_client_id']);
            if ($c && $c['status'] === 'active' && (int) $c['sim_id'] === (int) $s['id']) {
                return ['name' => $c['name'], 'phone' => (string) $c['phone'], 'whatsapp' => (string) ($c['whatsapp'] ?? ''), 'client_id' => (int) $c['id']];
            }
        }
        if (isset($s['contact_name']) && trim((string) $s['contact_name']) !== '') {
            return ['name' => $s['contact_name'], 'phone' => (string) $s['contact_phone'], 'whatsapp' => (string) $s['contact_whatsapp'], 'client_id' => 0];
        }

        return null;
    }

    /** $d: contact = '' (none) | client id on this SIM | 'other' + contact_name/phone/whatsapp. */
    public function setContact($simId, array $d): ?string
    {
        $s = $this->sim($simId);
        if (! $s) {
            return 'That SIM no longer exists.';
        }
        $pick = trim((string) ($d['contact'] ?? ''));
        $row = ['contact_client_id' => null, 'contact_name' => null, 'contact_phone' => null, 'contact_whatsapp' => null];
        if ($pick === 'other') {
            $name = trim((string) ($d['contact_name'] ?? ''));
            if ($name === '') {
                return 'Type the main contact\'s name.';
            }
            $row['contact_name'] = mb_substr($name, 0, 150);
            $row['contact_phone'] = mb_substr(trim((string) ($d['contact_phone'] ?? '')), 0, 40);
            $row['contact_whatsapp'] = mb_substr(trim((string) ($d['contact_whatsapp'] ?? '')), 0, 40);
        } elseif ($pick !== '') {
            $c = $this->client((int) $pick);
            if (! $c || $c['status'] !== 'active' || (int) $c['sim_id'] !== (int) $s['id']) {
                return 'Choose the main contact from the clients on this SIM.';
            }
            $row['contact_client_id'] = (int) $c['id'];
        }
        $this->db()->table('ds_sims')->where('id', (int) $simId)->update($row);

        return null;
    }

    /** A client who is a main contact leaves his SIM: he stays its main contact (as someone not on the SIM). */
    protected function contactLeft(array $c, string $why): void
    {
        foreach ($this->rows($this->db()->table('ds_sims')->where('contact_client_id', (int) $c['id'])) as $s) {
            $this->db()->table('ds_sims')->where('id', (int) $s['id'])->update(['contact_client_id' => null, 'contact_name' => mb_substr($c['name'], 0, 150), 'contact_phone' => $c['phone'], 'contact_whatsapp' => $c['whatsapp'] ?? null]);
            $this->addNote($c['name'].' was '.$why.' but is still the main contact of '.$s['number'].'. Change it in Edit SIM if someone else is now responsible.');
        }
    }

    // ---- SIMs ------------------------------------------------------------------------------------

    /** @return array{0: ?int, 1: ?string} new SIM id, error */
    public function addSim(array $d): array
    {
        $num = trim((string) ($d['number'] ?? ''));
        if ($num === '') {
            return [null, 'Type the SIM number.'];
        }
        if ($this->db()->table('ds_sims')->where('number', $num)->where('status', 'active')->exists()) {
            return [null, 'That SIM number is already on the board.'];
        }
        $kind = ($d['kind'] ?? '') === 'full' ? 'full' : 'shared';
        $gb = max(1, (int) ($d['gb'] ?? 0) ?: 200);
        $cost = $this->moneyIn($d['cost'] ?? '');
        if ($cost === null) {
            return [null, 'Type what the SIM package costs.'];
        }
        $method = $this->methodOk($d['method'] ?? '') ? $d['method'] : 'Bank';
        $until = $this->validDate($d['pkg_until'] ?? '') ?: (! empty($d['paid_now']) ? $this->addMonths($this->today(), 1) : null);
        $id = $this->db()->table('ds_sims')->insertGetId(['number' => mb_substr($num, 0, 40), 'kind' => $kind, 'gb' => $gb, 'cost' => $cost, 'method' => $method, 'pkg_until' => $until, 'status' => 'active', 'notes' => null, 'created_at' => date('Y-m-d H:i:s')]);
        if (! empty($d['paid_now'])) {
            $err = $this->addExpense(['cat' => 'Data packages', 'amount' => $cost, 'method' => $method, 'date' => $this->today(), 'text' => 'Package for '.$num.' - '.$gb.' GB', 'sim_id' => $id, 'auto' => 1]);
            if ($err) {
                return [$id, 'The SIM was added, but its package could not be recorded: '.$err];
            }
        }

        return [(int) $id, null];
    }

    public function saveSim($id, array $d): ?string
    {
        $s = $this->sim($id);
        if (! $s) {
            return 'That SIM no longer exists.';
        }
        $cost = $this->moneyIn($d['cost'] ?? '');
        if ($cost === null) {
            return 'Type what the SIM package costs.';
        }
        $num = trim((string) ($d['number'] ?? ''));
        if ($num === '') {
            return 'Type the SIM number.';
        }
        $row = ['number' => mb_substr($num, 0, 40), 'cost' => $cost, 'method' => $this->methodOk($d['method'] ?? '') ? $d['method'] : $s['method']];
        if (array_key_exists('pkg_until', $d)) {
            $row['pkg_until'] = $this->validDate($d['pkg_until']) ?: null;
        }
        $this->db()->table('ds_sims')->where('id', (int) $id)->update($row);

        return null;
    }

    /** Each SIM's MTN package runs out on its own date (pkg_until). Renewing moves it on one month; the cost is added to Money Out when the books are closed. */
    public function renewSim($id, $until = null): ?string
    {
        $s = $this->sim($id);
        if (! $s || $s['status'] !== 'active') {
            return 'That SIM no longer exists.';
        }
        $new = $this->validDate($until);
        if (! $new) {
            $from = ! empty($s['pkg_until']) && $s['pkg_until'] >= $this->today() ? $s['pkg_until'] : $this->today();
            $new = $this->addMonths($from, 1);
        }
        $this->db()->table('ds_sims')->where('id', (int) $id)->update(['pkg_until' => $new]);

        return null;
    }

    /** SIMs whose package runs out within $days days (or already ran out), soonest first. */
    public function packagesDue(int $days = 3): array
    {
        $rows = $this->rows($this->db()->table('ds_sims')->where('status', 'active')->whereNotNull('pkg_until')->where('pkg_until', '<=', $this->addDays($this->today(), $days))->orderBy('pkg_until'));
        foreach ($rows as &$r) {
            $r['clients_n'] = $this->db()->table('ds_clients')->where('sim_id', (int) $r['id'])->where('status', 'active')->count();
            $r['days'] = $this->daysFromToday($r['pkg_until']);
        }

        return $rows;
    }

    /** Average SIM package cost per GB by type: ['shared' => x|null, 'full' => y|null]. */
    public function costPerGb(): array
    {
        $out = ['shared' => null, 'full' => null];
        foreach (['shared', 'full'] as $k) {
            $rows = $this->rows($this->db()->table('ds_sims')->where('status', 'active')->where('kind', $k));
            $gb = array_sum(array_map(fn ($r) => (int) $r['gb'], $rows));
            $cost = array_sum(array_map(fn ($r) => (float) $r['cost'], $rows));
            if ($gb > 0) {
                $out[$k] = round($cost / $gb, 4);
            }
        }

        return $out;
    }

    /** WhatsApp receipt for a payment or recharge: [name, phone, msg], or null. */
    public function receipt($paymentId, string $company, string $sym): ?array
    {
        $p = $this->arr($this->db()->table('ds_payments')->where('id', (int) $paymentId)->first());
        if (! $p || $p['void']) {
            return null;
        }
        $c = $this->client($p['client_id']);
        if (! $c) {
            return null;
        }
        $m = fn ($v) => $sym.number_format((float) $v, 2);
        $dt = fn ($v) => date('j M Y', strtotime($v));
        $first = explode(' ', trim($c['name']))[0];
        $msg = 'Salaam '.$first.",\nWe received ".$m($p['amount']).' ('.$p['method'].') on '.$dt($p['date']);
        if ($p['kind'] === 'recharge') {
            $msg .= ' for a '.(int) $p['gb']." GB recharge.\n";
        } else {
            $msg .= ' for your '.(int) $p['gb']." GB data.\n";
            if ((int) $p['months'] > 0) {
                $msg .= 'You are paid until '.$dt($c['next_due']).'.'.((float) $c['credit'] > 0 ? ' '.$m($c['credit']).' is kept toward your next month.' : '')."\n";
            } else {
                $msg .= 'Part payment: '.$m($this->owed($c)).' is still owed, due '.$dt($c['next_due']).".\n";
            }
        }
        $msg .= "Thank you.\n".$company;

        return ['name' => $c['name'], 'phone' => ! empty($c['whatsapp']) ? $c['whatsapp'] : $c['phone'], 'msg' => $msg];
    }

    public function retireSim($id): ?string
    {
        $s = $this->sim($id);
        if (! $s) {
            return 'That SIM no longer exists.';
        }
        if ($this->clients($id)) {
            return 'Move or remove its clients first.';
        }
        $this->db()->table('ds_sims')->where('id', (int) $id)->update(['status' => 'closed']);

        return null;
    }

    // ---- clients ---------------------------------------------------------------------------------

    /** @return array{0: ?int, 1: ?string} new client id, error */
    public function addClient($simId, array $d): array
    {
        $s = $this->sim($simId);
        if (! $s || $s['status'] !== 'active') {
            return [null, 'That SIM no longer exists.'];
        }
        if ($this->freeSlots($s) < 1) {
            return [null, 'This SIM has no free slot.'];
        }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            return [null, 'Type the client\'s name.'];
        }
        $gb = (int) ($d['gb'] ?? 0);
        $ok = false;
        foreach ($this->plansFor($s['kind']) as $p) {
            if ((int) $p['gb'] === $gb) {
                $ok = true;
            }
        }
        if (! $ok) {
            return [null, 'Choose a plan.'];
        }
        $spare = $this->spareGb($s);
        if ($gb > $spare) {
            return [null, 'Only '.max(0, $spare).' GB is spare on '.$s['number'].'. Pick a smaller plan or another SIM.'];
        }
        $price = $this->moneyIn($d['price'] ?? '') ?: $this->planPrice($gb, $s['kind']);
        $due = $this->validDate($d['next_due'] ?? '') ?: $this->today();
        $id = $this->db()->table('ds_clients')->insertGetId(['sim_id' => (int) $simId, 'name' => mb_substr($name, 0, 150), 'phone' => mb_substr(trim((string) ($d['phone'] ?? '')), 0, 40),
            'whatsapp' => mb_substr(trim((string) ($d['whatsapp'] ?? '')), 0, 40), 'gb' => $gb, 'price' => $price, 'next_due' => $due, 'due_day' => (int) date('j', strtotime($due)),
            'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);

        return [(int) $id, null];
    }

    public function saveClient($id, array $d): ?string
    {
        $c = $this->client($id);
        if (! $c || $c['status'] !== 'active') {
            return 'That client no longer exists.';
        }
        $s = $this->sim($c['sim_id']);
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            return 'Type the client\'s name.';
        }
        $gb = (int) ($d['gb'] ?? 0);
        $ok = false;
        foreach ($this->plansFor($s['kind'], $c['gb']) as $p) {
            if ((int) $p['gb'] === $gb) {
                $ok = true;
            }
        }
        if (! $ok) {
            return 'Choose a plan.';
        }
        if ($gb - (int) $c['gb'] > $this->spareGb($s)) {
            return 'Only '.max(0, $this->spareGb($s)).' GB is spare on '.$s['number'].'. Move the client to a SIM with more space first.';
        }
        $price = $this->moneyIn($d['price'] ?? '');
        if ($price === null) {
            return 'Type the price.';
        }
        $due = $this->validDate($d['next_due'] ?? '');
        if (! $due) {
            return 'Choose the next due date.';
        }
        if ($gb !== (int) $c['gb']) {
            $this->addNote('Change '.$name.' ('.$c['phone'].') on '.$s['number'].' from '.$c['gb'].' GB to '.$gb.' GB.');
        }
        $this->db()->table('ds_clients')->where('id', (int) $id)->update(['name' => mb_substr($name, 0, 150), 'phone' => mb_substr(trim((string) ($d['phone'] ?? '')), 0, 40),
            'whatsapp' => mb_substr(trim((string) ($d['whatsapp'] ?? '')), 0, 40), 'gb' => $gb, 'price' => $price, 'next_due' => $due] + ($due !== $c['next_due'] ? ['due_day' => (int) date('j', strtotime($due))] : []));

        return null;
    }

    public function removeClient($id): ?string
    {
        $c = $this->client($id);
        if (! $c || $c['status'] !== 'active') {
            return 'That client no longer exists.';
        }
        $this->db()->table('ds_clients')->where('id', (int) $id)->update(['status' => 'removed']);
        $this->contactLeft($c, 'removed');
        $s = $this->sim($c['sim_id']);
        $this->addNote('Stop the data share to '.$c['name'].' ('.$c['phone'].') on '.($s ? $s['number'] : 'its SIM').'.');

        return null;
    }

    /** Move clients to another SIM, or to a new SIM number ($to = 'new' + $new). */
    public function moveClients($fromId, $ids, $to, array $new = [], string $why = 'Moved'): ?string
    {
        $from = $this->sim($fromId);
        if (! $from) {
            return 'That SIM no longer exists.';
        }
        $move = [];
        foreach ((array) $ids as $id) {
            $c = $this->client($id);
            if ($c && $c['status'] === 'active' && (int) $c['sim_id'] === (int) $fromId) {
                $move[] = $c;
            }
        }
        if (! $move) {
            return 'Tick at least one client.';
        }
        $gb = array_sum(array_map(fn ($c) => (int) $c['gb'], $move));
        if ($to === 'new') {
            [$toId, $err] = $this->addSim(['number' => $new['number'] ?? '', 'kind' => 'shared', 'gb' => 200, 'cost' => $new['cost'] ?? '', 'method' => $new['method'] ?? 'Bank', 'paid_now' => ! empty($new['paid_now'])]);
            if ($err && ! $toId) {
                return $err;
            }
            $dest = $this->sim($toId);
        } else {
            $dest = $this->sim($to);
            if (! $dest || $dest['status'] !== 'active' || (int) $dest['id'] === (int) $fromId) {
                return 'Choose the SIM to move them to.';
            }
        }
        if (count($move) > $this->freeSlots($dest)) {
            return $dest['number'].' has only '.$this->freeSlots($dest).' free slot'.($this->freeSlots($dest) === 1 ? '' : 's').'.';
        }
        if ($gb > $this->spareGb($dest)) {
            return $dest['number'].' has only '.max(0, $this->spareGb($dest)).' GB spare; these clients need '.$gb.' GB.';
        }
        $cyc = (int) $this->openCycle()['id'];
        foreach ($move as $c) {
            $this->contactLeft($c, 'moved to '.$dest['number']);
            $this->db()->table('ds_clients')->where('id', (int) $c['id'])->update(['sim_id' => (int) $dest['id']]);
            $this->db()->table('ds_moves')->insert(['cycle_id' => $cyc, 'date' => $this->today(), 'client_id' => (int) $c['id'], 'name' => $c['name'], 'from_sim' => $from['number'], 'to_sim' => $dest['number'], 'why' => $why]);
        }
        $this->addNote('Move '.implode(', ', array_map(fn ($c) => $c['name'].' ('.$c['gb'].' GB)', $move)).' from '.$from['number'].' to '.$dest['number'].'.');

        return null;
    }

    // ---- money in --------------------------------------------------------------------------------

    public function pay($clientId, array $d): ?string
    {
        if ($e = $this->endedError()) {
            return $e;
        }
        $c = $this->client($clientId);
        if (! $c || $c['status'] !== 'active') {
            return 'That client no longer exists.';
        }
        $amount = $this->moneyIn($d['amount'] ?? '');
        if ($amount === null) {
            return 'Type the amount the client paid.';
        }
        if (! $this->methodOk($d['method'] ?? '')) {
            return 'Choose how the client paid.';
        }
        $date = $this->validDate($d['date'] ?? '');
        $cyc = $this->openCycle();
        if (! $date) {
            return 'Choose the date.';
        }
        if ($date < $cyc['start']) {
            return 'Cycle '.$cyc['no'].' starts on '.Money::date($cyc['start']).' - earlier days are closed.';
        }
        if ($date > $this->today()) {
            return 'The date cannot be in the future.';
        }
        if ($e = $this->locked($date)) {
            return $e;
        }
        // part payments: the amount plus what was already paid toward the next month decides how many whole months are paid; the rest is kept (credit)
        $price = (float) $c['price'];
        $total = round((float) $c['credit'] + (float) $amount, 2);
        $months = $price > 0 ? (int) floor(($total + 0.004) / $price) : 1;
        if ($months > 24) {
            return 'That pays more than 24 months. Check the amount.';
        }
        $credit = $price > 0 ? max(0, round($total - $months * $price, 2)) : 0;
        $s = $this->sim($c['sim_id']);
        $entry = $this->post('receipt', $date, 'Data sharing: '.$c['name'].' paid '.$this->planName($c['gb']).($months > 1 ? ' x'.$months : ($months === 0 ? ' (part payment)' : '')).' ('.$d['method'].')',
            $this->ledger('method:'.$d['method']), $this->ledger('income'), $amount, $c['name'].' - '.$c['gb'].' GB on '.($s ? $s['number'] : ''));
        if (! $entry) {
            return 'The payment could not be posted to the books. Nothing was saved.';
        }
        $this->lastPaymentId = (int) $this->db()->table('ds_payments')->insertGetId(['cycle_id' => (int) $cyc['id'], 'client_id' => (int) $c['id'], 'sim_id' => (int) $c['sim_id'], 'kind' => 'plan', 'gb' => (int) $c['gb'],
            'months' => $months, 'plan_price' => $price, 'amount' => $amount, 'date' => $date, 'method' => $d['method'], 'entry_id' => $entry, 'created_by' => $this->who(), 'created_at' => date('Y-m-d H:i:s')]);
        $this->db()->table('ds_clients')->where('id', (int) $c['id'])->update(['next_due' => $months ? $this->addMonths($c['next_due'], $months, $this->dueDay($c)) : $c['next_due'], 'credit' => $credit]);

        return null;
    }

    /**
     * A recharge: the client finished the data early and pays again, the same price as the plan. $d['src'] = 'spare' (spare GB on the SIM),
     * 'bought' (extra data bought on the SIM - cost + method) or 'moved' (to a free slot on SIM $d['to']).
     */
    public function recharge($clientId, array $d): ?string
    {
        if ($e = $this->endedError()) {
            return $e;
        }
        $c = $this->client($clientId);
        if (! $c || $c['status'] !== 'active') {
            return 'That client no longer exists.';
        }
        $s = $this->sim($c['sim_id']);
        $amount = $this->moneyIn($d['amount'] ?? '');
        if ($amount === null) {
            return 'Type the amount the client paid.';
        }
        if (! $this->methodOk($d['method'] ?? '')) {
            return 'Choose how the client paid.';
        }
        $date = $this->validDate($d['date'] ?? '') ?: $this->today();
        $cyc = $this->openCycle();
        if ($date < $cyc['start'] || $date > $this->today()) {
            return 'Pick a date in cycle '.$cyc['no'].', not in the future.';
        }
        if ($e = $this->locked($date)) {
            return $e;
        }
        $src = in_array($d['src'] ?? '', ['spare', 'bought', 'moved'], true) ? $d['src'] : '';
        if ($src === '') {
            return 'Choose where the data comes from.';
        }
        $note = null;
        $simId = (int) $c['sim_id'];
        $cost = null;
        $dest = null;
        if ($src === 'spare' && $this->spareGb($s) < (int) $c['gb']) {
            return 'Only '.max(0, $this->spareGb($s)).' GB is spare on '.$s['number'].'.';
        }
        if ($src === 'bought') {
            $cost = $this->moneyIn($d['cost'] ?? '');
            if ($cost === null) {
                return 'Type what the extra data cost.';
            }
            if (! $this->methodOk($d['cost_method'] ?? '')) {
                return 'Choose how the extra data was paid.';
            }
        }
        if ($src === 'moved') {
            $dest = $this->sim($d['to'] ?? 0);
            if (! $dest || $dest['status'] !== 'active' || (int) $dest['id'] === (int) $s['id']) {
                return 'Choose the SIM to move the client to.';
            }
            if ($this->freeSlots($dest) < 1 || $this->spareGb($dest) < (int) $c['gb']) {
                return $dest['number'].' needs a free slot and '.$c['gb'].' GB spare.';
            }
        }
        $entry = $this->post('receipt', $date, 'Data sharing: '.$c['name'].' recharged '.$c['gb'].' GB ('.$d['method'].')', $this->ledger('method:'.$d['method']), $this->ledger('recharge'), $amount, $c['name'].' - recharge '.$c['gb'].' GB');
        if (! $entry) {
            return 'The recharge could not be posted to the books. Nothing was saved.';
        }
        if ($src === 'bought') {
            $err = $this->addExpense(['cat' => 'Extra data', 'amount' => $cost, 'method' => $d['cost_method'], 'date' => $date, 'text' => 'Extra '.$c['gb'].' GB on '.$s['number'].' for '.$c['name'], 'sim_id' => (int) $s['id'], 'client_id' => (int) $c['id']]);
            if ($err) {
                $this->dropEntry($entry);

                return $err;
            }
        }
        if ($src === 'moved') {
            $err = $this->moveClients($s['id'], [$c['id']], $dest['id'], [], 'Recharge');
            if ($err) {
                $this->dropEntry($entry);

                return $err;
            }
            $simId = (int) $dest['id'];
            $note = 'Moved from '.$s['number'];
            $src = 'spare';
        }
        $this->lastPaymentId = (int) $this->db()->table('ds_payments')->insertGetId(['cycle_id' => (int) $cyc['id'], 'client_id' => (int) $c['id'], 'sim_id' => $simId, 'plan_price' => (float) $c['price'], 'kind' => 'recharge',
            'gb' => (int) $c['gb'], 'months' => 0, 'amount' => $amount, 'date' => $date, 'method' => $d['method'], 'src' => $src, 'note' => $note, 'entry_id' => $entry, 'created_by' => $this->who(), 'created_at' => date('Y-m-d H:i:s')]);

        return null;
    }

    public function voidPayment($id): ?string
    {
        $p = $this->arr($this->db()->table('ds_payments')->where('id', (int) $id)->first());
        if (! $p || $p['void']) {
            return 'That payment no longer exists.';
        }
        $cyc = $this->openCycle();
        if ((int) $p['cycle_id'] !== (int) $cyc['id']) {
            return 'That payment is in a closed cycle and cannot be changed.';
        }
        if ($e = $this->locked($p['date'])) {
            return $e;
        }
        $this->dropEntry($p['entry_id']);
        $this->db()->table('ds_payments')->where('id', (int) $id)->update(['void' => 1]);
        if ($p['kind'] === 'plan') {
            $c = $this->client($p['client_id']);
            if ($c) {
                // undo: take the payment back out of what was paid (part payments)
                $price = $p['plan_price'] !== null ? (float) $p['plan_price'] : (float) $c['price'];
                $months = (int) $p['months'];
                $credit = round((float) $c['credit'] - (float) $p['amount'] + $months * $price, 2);
                if ($p['plan_price'] === null) {
                    $credit = (float) $c['credit'];
                }
                while ($credit < -0.004 && $price > 0) {
                    $months++;
                    $credit = round($credit + $price, 2);
                }
                $this->db()->table('ds_clients')->where('id', (int) $c['id'])->update(['next_due' => $months ? $this->addMonths($c['next_due'], -$months, $this->dueDay($c)) : $c['next_due'], 'credit' => max(0, $credit)]);
            }
        }

        return null;
    }

    // ---- money out -------------------------------------------------------------------------------

    public function addExpense(array $d): ?string
    {
        if ($e = $this->endedError()) {
            return $e;
        }
        if (! isset(self::CATS[$d['cat'] ?? ''])) {
            return 'Choose what the money was for.';
        }
        $amount = $this->moneyIn($d['amount'] ?? '');
        if ($amount === null) {
            return 'Type how much was paid.';
        }
        if (! $this->methodOk($d['method'] ?? '')) {
            return 'Choose how it was paid.';
        }
        $date = $this->validDate($d['date'] ?? '') ?: $this->today();
        $cyc = $this->openCycle();
        if ($date < $cyc['start'] || $date > $this->today()) {
            return 'Pick a date in cycle '.$cyc['no'].', not in the future.';
        }
        if ($e = $this->locked($date)) {
            return $e;
        }
        $text = trim((string) ($d['text'] ?? '')) ?: $d['cat'];
        $entry = $this->post('payment', $date, 'Data sharing: '.$text, $this->ledger(self::CATS[$d['cat']]), $this->ledger('method:'.$d['method']), $amount, $text);
        if (! $entry) {
            return 'It could not be posted to the books. Nothing was saved.';
        }
        $this->db()->table('ds_expenses')->insert(['cycle_id' => (int) $cyc['id'], 'date' => $date, 'cat' => $d['cat'], 'text' => mb_substr($text, 0, 255), 'amount' => $amount, 'method' => $d['method'],
            'sim_id' => ! empty($d['sim_id']) ? (int) $d['sim_id'] : null, 'client_id' => ! empty($d['client_id']) ? (int) $d['client_id'] : null, 'auto' => ! empty($d['auto']) ? 1 : 0,
            'entry_id' => $entry, 'created_by' => $this->who(), 'created_at' => date('Y-m-d H:i:s')]);

        return null;
    }

    /** Money In that is not a client payment: other income, SIM card sales, owner's money, a loan. */
    public function addIncome(array $d): ?string
    {
        if ($e = $this->endedError()) {
            return $e;
        }
        if (! isset(self::CATS_IN[$d['cat'] ?? ''])) {
            return 'Choose what the money is for.';
        }
        $amount = $this->moneyIn($d['amount'] ?? '');
        if ($amount === null) {
            return 'Type how much came in.';
        }
        if (! $this->methodOk($d['method'] ?? '')) {
            return 'Choose how it came in.';
        }
        $date = $this->validDate($d['date'] ?? '') ?: $this->today();
        $cyc = $this->openCycle();
        if ($date < $cyc['start'] || $date > $this->today()) {
            return 'Pick a date in cycle '.$cyc['no'].', not in the future.';
        }
        if ($e = $this->locked($date)) {
            return $e;
        }
        $text = trim((string) ($d['text'] ?? '')) ?: $d['cat'];
        $entry = $this->post('receipt', $date, 'Data sharing money in: '.$text, $this->ledger('method:'.$d['method']), $this->ledger(self::CATS_IN[$d['cat']]), $amount, $text);
        if (! $entry) {
            return 'It could not be posted to the books. Nothing was saved.';
        }
        $this->db()->table('ds_income')->insert(['cycle_id' => (int) $cyc['id'], 'date' => $date, 'cat' => $d['cat'], 'text' => mb_substr($text, 0, 255), 'amount' => $amount, 'method' => $d['method'],
            'entry_id' => $entry, 'created_by' => $this->who(), 'created_at' => date('Y-m-d H:i:s')]);

        return null;
    }

    public function voidIncome($id): ?string
    {
        $x = $this->arr($this->db()->table('ds_income')->where('id', (int) $id)->first());
        if (! $x || $x['void']) {
            return 'That money in no longer exists.';
        }
        if ((int) $x['cycle_id'] !== (int) $this->openCycle()['id']) {
            return 'That is in a closed cycle and cannot be changed.';
        }
        if ($e = $this->locked($x['date'])) {
            return $e;
        }
        $this->dropEntry($x['entry_id']);
        $this->db()->table('ds_income')->where('id', (int) $id)->update(['void' => 1]);

        return null;
    }

    public function voidExpense($id): ?string
    {
        $x = $this->arr($this->db()->table('ds_expenses')->where('id', (int) $id)->first());
        if (! $x || $x['void']) {
            return 'That payment no longer exists.';
        }
        if ((int) $x['cycle_id'] !== (int) $this->openCycle()['id']) {
            return 'That payment is in a closed cycle and cannot be changed.';
        }
        if ($e = $this->locked($x['date'])) {
            return $e;
        }
        $this->dropEntry($x['entry_id']);
        $this->db()->table('ds_expenses')->where('id', (int) $id)->update(['void' => 1]);

        return null;
    }

    /** Split other money in: [income total (P&L), other total, by category, rows]. */
    protected function incomeSplit(array $rows): array
    {
        $inc = $oth = 0;
        $byCat = [];
        foreach ($rows as $r) {
            $a = (float) $r['amount'];
            $byCat[$r['cat']] = ($byCat[$r['cat']] ?? 0) + $a;
            in_array($r['cat'], self::INCOME_IN, true) ? $inc += $a : $oth += $a;
        }

        return [$inc, $oth, $byCat, array_map(fn ($r) => ['id' => (int) $r['id'], 'cycle_id' => (int) $r['cycle_id'], 'date' => $r['date'], 'cat' => $r['cat'], 'text' => $r['text'], 'method' => $r['method'], 'amount' => (float) $r['amount']], $rows)];
    }

    // ---- MTN to-do notes -------------------------------------------------------------------------

    public function notes(): array
    {
        return $this->rows($this->db()->table('ds_notes')->where('done', 0)->orderBy('id'));
    }

    public function addNote(string $text): void
    {
        $this->db()->table('ds_notes')->insert(['text' => mb_substr($text, 0, 500), 'done' => 0, 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function noteDone($id): void
    {
        $this->db()->table('ds_notes')->where('id', (int) $id)->update(['done' => 1]);
    }

    // ---- the cycle in numbers --------------------------------------------------------------------

    /** Everything the pages, reports, exports and the presentation show for a cycle. Live for the open cycle; a closed cycle returns what was saved when it was closed. */
    public function cycleData($cycleId = null): ?array
    {
        $cyc = $cycleId ? $this->cycle($cycleId) : $this->openCycle();
        if (! $cyc) {
            return null;
        }
        if ($cyc['status'] === 'closed' && $cyc['snapshot']) {
            $d = json_decode($cyc['snapshot'], true);
            if (is_array($d)) {
                $d['closed'] = true;
                $d['closed_on'] = $cyc['closed_on'];

                return $d;
            }
        }
        $cid = (int) $cyc['id'];
        $pays = $this->payments($cid);
        $exps = $this->expenses($cid);
        $byClient = [];
        foreach ($pays as $p) {
            $byClient[$p['client_id']][] = $p;
        }
        $pkgBySim = $extraBySim = $extraByClient = $byCat = [];
        foreach ($exps as $e) {
            $byCat[$e['cat']] = ($byCat[$e['cat']] ?? 0) + (float) $e['amount'];
            if ($e['cat'] === 'Data packages' && $e['sim_id']) {
                $pkgBySim[$e['sim_id']] = ($pkgBySim[$e['sim_id']] ?? 0) + (float) $e['amount'];
            }
            if ($e['cat'] === 'Extra data') {
                if ($e['sim_id']) {
                    $extraBySim[$e['sim_id']] = ($extraBySim[$e['sim_id']] ?? 0) + (float) $e['amount'];
                }
                if ($e['client_id']) {
                    $extraByClient[$e['client_id']] = ($extraByClient[$e['client_id']] ?? 0) + (float) $e['amount'];
                }
            }
        }
        $sims = $all = [];
        $usage = $this->usageMap();
        $sumAmt = fn (array $ps) => array_sum(array_map(fn ($p) => (float) $p['amount'], $ps));
        foreach ($this->sims() as $s) {
            $pkg = $pkgBySim[$s['id']] ?? 0;
            $cl = [];
            $con = $s['kind'] === 'full' ? null : $this->simContact($s);
            foreach ($this->clients($s['id']) as $c) {
                $ps = $byClient[$c['id']] ?? [];
                $plan = array_values(array_filter($ps, fn ($p) => $p['kind'] === 'plan'));
                $rc = array_values(array_filter($ps, fn ($p) => $p['kind'] === 'recharge'));
                $income = $sumAmt($ps);
                $share = round($pkg * (int) $c['gb'] / max(1, (int) $s['gb']), 2);
                $extra = $extraByClient[$c['id']] ?? 0;
                $u = $usage[$c['id']] ?? null;
                $row = ['id' => (int) $c['id'], 'name' => $c['name'], 'phone' => $c['phone'], 'whatsapp' => (string) ($c['whatsapp'] ?? ''), 'gb' => (int) $c['gb'], 'price' => (float) $c['price'], 'next' => $c['next_due'],
                    'status' => $this->status($c, $cid), 'sim' => $s['number'],
                    'plan_amt' => $sumAmt($plan), 'plan_date' => $plan ? end($plan)['date'] : '', 'plan_method' => implode(', ', array_unique(array_map(fn ($p) => $p['method'], $plan))),
                    'rech_n' => count($rc), 'rech_amt' => $sumAmt($rc), 'rech_method' => implode(', ', array_unique(array_map(fn ($p) => $p['method'], $rc))),
                    'income' => $income, 'share' => $share, 'extra' => $extra, 'profit' => round($income - $share - $extra, 2),
                    'credit' => round((float) ($c['credit'] ?? 0), 2), 'owed' => $this->owed($c),
                    'used' => $u['used'] ?? null, 'left' => $u['left'] ?? null, 'used_of' => $u['of_gb'] ?? (int) $c['gb'], 'read_on' => $u['date'] ?? '',
                    'main' => $con && (int) $con['client_id'] === (int) $c['id'], 'mc' => $con && (int) $con['client_id'] !== (int) $c['id'] ? $con['name'] : ''];
                $cl[] = $row;
                $all[] = $row;
            }
            $used = $this->usedGb($s['id']);
            $income = array_sum(array_map(fn ($r) => $r['income'], $cl));
            $extraS = $extraBySim[$s['id']] ?? 0;
            $sims[] = ['id' => (int) $s['id'], 'number' => $s['number'], 'kind' => $s['kind'], 'gb' => (int) $s['gb'], 'used' => $used, 'spare' => $this->spareGb($s, $cid),
                'slots' => $this->slots($s), 'free_slots' => $this->freeSlots($s), 'pkg' => $pkg, 'extra' => $extraS,
                'free_cost' => round($pkg * max(0, (int) $s['gb'] - $used) / max(1, (int) $s['gb']), 2),
                'income' => $income, 'cost' => $pkg + $extraS, 'profit' => round($income - $pkg - $extraS, 2), 'clients' => $cl, 'contact' => $con, 'pkg_until' => $s['pkg_until'] ?? null, 'cost_full' => (float) $s['cost']];
        }
        $names = $this->db()->table('ds_clients')->pluck('name', 'id')->all();
        $simNames = $this->db()->table('ds_sims')->pluck('number', 'id')->all();
        $payRows = [];
        $nth = [];
        foreach ($pays as $p) {
            $t = 'Plan payment';
            if ($p['kind'] === 'recharge') {
                $nth[$p['client_id']] = ($nth[$p['client_id']] ?? 0) + 1;
                $t = 'Recharge '.$nth[$p['client_id']];
            }
            $payRows[] = ['id' => (int) $p['id'], 'date' => $p['date'], 'client' => $names[$p['client_id']] ?? '', 'client_id' => (int) $p['client_id'], 'sim' => $simNames[$p['sim_id']] ?? '',
                'type' => $t, 'gb' => (int) $p['gb'], 'method' => $p['method'], 'src' => $p['kind'] === 'recharge' ? ($p['note'] ? 'Moved to another SIM' : ($p['src'] === 'bought' ? 'Bought extra data' : 'Spare GB on SIM')) : '', 'amount' => (float) $p['amount']];
        }
        $planIn = $rechIn = 0;
        foreach ($pays as $p) {
            $p['kind'] === 'plan' ? $planIn += (float) $p['amount'] : $rechIn += (float) $p['amount'];
        }
        $out = array_sum($byCat);
        $oin = $this->rows($this->db()->table('ds_income')->where('cycle_id', $cid)->where('void', 0)->orderByDesc('date')->orderByDesc('id'));
        [$otherIn, $capIn, $inCat, $inRows] = $this->incomeSplit($oin);
        $methods = [];
        foreach (self::METHODS as $m) {
            $in = $o = 0;
            foreach ($pays as $p) {
                if ($p['method'] === $m) {
                    $in += (float) $p['amount'];
                }
            }
            foreach ($oin as $p) {
                if ($p['method'] === $m) {
                    $in += (float) $p['amount'];
                }
            }
            foreach ($exps as $e) {
                if ($e['method'] === $m) {
                    $o += (float) $e['amount'];
                }
            }
            $methods[] = ['m' => $m, 'in' => $in, 'out' => $o];
        }
        $moves = $this->rows($this->db()->table('ds_moves')->where('cycle_id', $cid)->orderBy('id'));

        return ['id' => $cid, 'no' => (int) $cyc['no'], 'start' => $cyc['start'], 'end' => $cyc['end'], 'closed' => false, 'closed_on' => null,
            'sims' => $sims, 'all' => $all, 'by_cat' => $byCat, 'methods' => $methods, 'plan_in' => $planIn, 'rech_in' => $rechIn, 'income' => $planIn + $rechIn,
            'pkgs' => $byCat['Data packages'] ?? 0, 'extra' => $byCat['Extra data'] ?? 0,
            'other_in' => $otherIn, 'capital_in' => $capIn, 'in_cat' => $inCat, 'in_rows' => $inRows,
            'out' => $out, 'net' => round($planIn + $rechIn + $otherIn - $out, 2), 'pay_rows' => $payRows,
            'exp_rows' => array_map(fn ($e) => ['id' => (int) $e['id'], 'date' => $e['date'], 'cat' => $e['cat'], 'text' => $e['text'], 'method' => $e['method'], 'amount' => (float) $e['amount'], 'auto' => (int) $e['auto']], $exps),
            'moves' => array_map(fn ($m) => ['date' => $m['date'], 'name' => $m['name'], 'from' => $m['from_sim'], 'to' => $m['to_sim'], 'why' => $m['why']], $moves)];
    }

    /**
     * Filtered money report - Reports and Money Out filters. $f: cycle_id | from + to (dates), sim_id, method, kind ('plan' | 'recharge'),
     * q (client name / phone / WhatsApp), cat (Money Out). Works for open and closed cycles and for any dates across cycles.
     */
    public function reportData(array $f): array
    {
        $db = $this->db();
        $period = function ($q, string $alias) use ($f) {
            if (! empty($f['cycle_id'])) {
                $q->where($alias.'cycle_id', (int) $f['cycle_id']);
            }
            if (! empty($f['from'])) {
                $q->where($alias.'date', '>=', $f['from']);
            }
            if (! empty($f['to'])) {
                $q->where($alias.'date', '<=', $f['to']);
            }
        };
        $pq = $db->table('ds_payments as p')->leftJoin('ds_clients as c', 'c.id', '=', 'p.client_id')->leftJoin('ds_sims as s', 's.id', '=', 'p.sim_id')
            ->selectRaw('p.*, c.name AS client, c.phone, c.whatsapp, c.gb AS plan_gb, s.number AS sim')->where('p.void', 0);
        $period($pq, 'p.');
        if (! empty($f['sim_id'])) {
            $pq->where('p.sim_id', (int) $f['sim_id']);
        }
        if (! empty($f['method'])) {
            $pq->where('p.method', $f['method']);
        }
        if (! empty($f['kind'])) {
            $pq->where('p.kind', $f['kind'] === 'other' ? 'none' : $f['kind']);
        }
        if (! empty($f['q'])) {
            $like = '%'.$f['q'].'%';
            $dig = preg_replace('/\D/', '', $f['q']);
            $pq->where(function ($w) use ($like, $dig) {
                $w->where('c.name', 'like', $like)->orWhere('c.phone', 'like', $like)->orWhere('c.whatsapp', 'like', $like);
                if (strlen($dig) >= 3) {
                    $w->orWhereRaw("REPLACE(c.phone, ' ', '') LIKE ?", ['%'.$dig.'%'])->orWhereRaw("REPLACE(c.whatsapp, ' ', '') LIKE ?", ['%'.$dig.'%']);
                }
            });
        }
        $pays = $this->rows($pq->orderBy('p.date')->orderBy('p.id'));
        $eq = $db->table('ds_expenses')->where('void', 0);
        $period($eq, '');
        if (! empty($f['sim_id'])) {
            $eq->where('sim_id', (int) $f['sim_id']);
        }
        if (! empty($f['method'])) {
            $eq->where('method', $f['method']);
        }
        if (! empty($f['cat'])) {
            $eq->where('cat', $f['cat']);
        }
        if (! empty($f['q_exp'])) {
            $eq->where(fn ($w) => $w->where('text', 'like', '%'.$f['q_exp'].'%')->orWhere('cat', 'like', '%'.$f['q_exp'].'%'));
        }
        $exps = $this->rows($eq->orderByDesc('date')->orderByDesc('id'));
        $planIn = $rechIn = 0;
        $byClient = $nth = $rows = [];
        foreach ($pays as $p) {
            $a = (float) $p['amount'];
            $p['kind'] === 'plan' ? $planIn += $a : $rechIn += $a;
            $k = (int) $p['client_id'];
            if (! isset($byClient[$k])) {
                $byClient[$k] = ['id' => $k, 'name' => (string) $p['client'], 'sim' => (string) $p['sim'], 'gb' => (int) $p['plan_gb'], 'plan_amt' => 0, 'plan_method' => [], 'rech_n' => 0, 'rech_amt' => 0, 'rech_method' => [], 'income' => 0];
            }
            $b = &$byClient[$k];
            if ($p['kind'] === 'plan') {
                $b['plan_amt'] += $a;
                $b['plan_method'][$p['method']] = 1;
            } else {
                $b['rech_n']++;
                $b['rech_amt'] += $a;
                $b['rech_method'][$p['method']] = 1;
                $nth[$k] = ($nth[$k] ?? 0) + 1;
            }
            $b['income'] += $a;
            unset($b);
            $rows[] = ['id' => (int) $p['id'], 'cycle_id' => (int) $p['cycle_id'], 'date' => $p['date'], 'client' => (string) $p['client'], 'client_id' => $k, 'sim' => (string) $p['sim'],
                'type' => $p['kind'] === 'plan' ? 'Plan payment' : 'Recharge '.$nth[$k], 'gb' => (int) $p['gb'], 'method' => $p['method'],
                'src' => $p['kind'] === 'recharge' ? ($p['note'] ? 'Moved to another SIM' : ($p['src'] === 'bought' ? 'Bought extra data' : 'Spare GB on SIM')) : '', 'amount' => $a];
        }
        foreach ($byClient as &$b) {
            $b['plan_method'] = implode(', ', array_keys($b['plan_method']));
            $b['rech_method'] = implode(', ', array_keys($b['rech_method']));
        }
        unset($b);
        usort($byClient, fn ($x, $y) => $y['income'] <=> $x['income']);
        $byCat = [];
        $out = 0;
        foreach ($exps as $e) {
            $byCat[$e['cat']] = ($byCat[$e['cat']] ?? 0) + (float) $e['amount'];
            $out += (float) $e['amount'];
        }
        // other money in: not tied to a SIM or client, nor to plan / recharge filters
        $oin = [];
        if (empty($f['sim_id']) && (empty($f['kind']) || $f['kind'] === 'other')) {
            $iq = $db->table('ds_income')->where('void', 0);
            $period($iq, '');
            if (! empty($f['method'])) {
                $iq->where('method', $f['method']);
            }
            if (! empty($f['cat_in'])) {
                $iq->where('cat', $f['cat_in']);
            }
            if (! empty($f['q'])) {
                $iq->where(fn ($w) => $w->where('text', 'like', '%'.$f['q'].'%')->orWhere('cat', 'like', '%'.$f['q'].'%'));
            }
            $oin = $this->rows($iq->orderByDesc('date')->orderByDesc('id'));
        }
        [$otherIn, $capIn, $inCat, $inRows] = $this->incomeSplit($oin);
        $methods = [];
        foreach (self::METHODS as $m) {
            $in = $o = 0;
            foreach ($pays as $p) {
                if ($p['method'] === $m) {
                    $in += (float) $p['amount'];
                }
            }
            foreach ($oin as $p) {
                if ($p['method'] === $m) {
                    $in += (float) $p['amount'];
                }
            }
            foreach ($exps as $e) {
                if ($e['method'] === $m) {
                    $o += (float) $e['amount'];
                }
            }
            $methods[] = ['m' => $m, 'in' => $in, 'out' => $o];
        }
        $simNames = $db->table('ds_sims')->pluck('number', 'id')->all();

        return ['plan_in' => $planIn, 'rech_in' => $rechIn, 'income' => $planIn + $rechIn, 'by_cat' => $byCat, 'out' => $out, 'net' => round($planIn + $rechIn + $otherIn - $out, 2),
            'other_in' => $otherIn, 'capital_in' => $capIn, 'in_cat' => $inCat, 'in_rows' => $inRows,
            'methods' => $methods, 'pay_rows' => $rows, 'by_client' => array_values($byClient),
            'exp_rows' => array_map(fn ($e) => ['id' => (int) $e['id'], 'cycle_id' => (int) $e['cycle_id'], 'date' => $e['date'], 'cat' => $e['cat'], 'text' => $e['text'], 'method' => $e['method'], 'amount' => (float) $e['amount'],
                'auto' => (int) $e['auto'], 'sim' => $e['sim_id'] && isset($simNames[$e['sim_id']]) ? $simNames[$e['sim_id']] : ''], $exps)];
    }

    /** Close the open cycle (from the 1st of the next month): save its numbers, lock it, open the next one with new SIM packages. Returns an error, or a "Cycle closed, but ..." warning. */
    public function closeCycle(): ?string
    {
        $cyc = $this->openCycle();
        if ($this->today() <= $cyc['end']) {
            return 'Cycle '.$cyc['no'].' runs until '.Money::date($cyc['end']).' - its books can be closed from the next day.';
        }
        $snap = $this->cycleData($cyc['id']);
        $this->db()->table('ds_cycles')->where('id', (int) $cyc['id'])->update(['status' => 'closed', 'closed_on' => $this->today(), 'closed_by' => $this->who(), 'snapshot' => json_encode($snap)]);
        $next = $this->openCycle();
        // a cycle missed completely (closed very late): start the new one today
        if ($next['end'] < $this->today()) {
            $this->db()->table('ds_cycles')->where('id', (int) $next['id'])->update(['start' => date('Y-m-01'), 'end' => $this->monthEnd($this->today())]);
            $next = $this->openCycle();
        }
        $fails = 0;
        foreach ($this->sims() as $s) {
            if ($this->addExpense(['cat' => 'Data packages', 'amount' => $s['cost'], 'method' => $s['method'], 'date' => $next['start'], 'text' => 'Package for '.$s['number'].' - '.$s['gb'].' GB', 'sim_id' => (int) $s['id'], 'auto' => 1])) {
                $fails++;
            }
        }

        return $fails ? 'Cycle closed, but '.$fails.' SIM package'.($fails > 1 ? 's' : '').' could not be recorded - add them under Money Out.' : null;
    }

    /** An error when the open cycle has ended and must be closed before more is recorded. */
    public function endedError(): ?string
    {
        $cyc = $this->openCycle();

        return $this->today() > $cyc['end'] ? 'Cycle '.$cyc['no'].' ended on '.Money::date($cyc['end']).'. Its books must be closed before anything new is recorded - then cycle '.((int) $cyc['no'] + 1).' starts.' : null;
    }
}
