<?php

namespace App\Services\Accounting;

use App\Models\Tenant\Entry;
use App\Models\Tenant\EntryType;
use App\Models\Tenant\Group;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Setting;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Journal entries: the entries made by hand (Journal, Money In, Money Out, Transfer and custom types).
 * Ported from the legacy Entries controller and Entry_model.
 *
 * An entry has two or more lines; the debits must equal the credits. The entry type's
 * `restriction_bankcash` says which accounts it may use:
 *   1 any   2 a bank/cash account on the debit side   3 a bank/cash account on the credit side
 *   4 only bank/cash accounts   5 only non-bank accounts
 */
class EntryService
{
    const NAMES = ['journal' => 'Journal', 'receipt' => 'Money In', 'payment' => 'Money Out', 'contra' => 'Transfer'];

    const COST_TYPES = ['materials' => 'Materials', 'labour' => 'Labour', 'equipment' => 'Equipment hire', 'subcontractors' => 'Subcontractors', 'other' => 'Other'];

    public function __construct(protected ChartOfAccounts $chart) {}

    // ---- entry types ------------------------------------------------------

    public function typeName(EntryType $t): string
    {
        return self::NAMES[$t->label] ?? $t->name;
    }

    public function typeByLabel(string $label): EntryType
    {
        return EntryType::where('label', $label)->firstOrFail();
    }

    /** "Journal 7", with the type's own prefix, suffix and zero padding. */
    public function entryNumber($number, EntryType $type): string
    {
        $n = $type->zero_padding > 0 ? str_pad((string) $number, $type->zero_padding, '0', STR_PAD_LEFT) : (string) $number;

        return $type->prefix.$n.$type->suffix;
    }

    public function nextNumber(int $typeId): int
    {
        return ((int) Entry::where('entrytype_id', $typeId)->max('number')) + 1;
    }

    // ---- the list ---------------------------------------------------------

    /** SQL subquery of every entry id that another module posted (invoices, bills, payments, ...). */
    protected function autoPostedSql(): string
    {
        $sources = [
            'payments' => ['entry_id'], 'vendor_payments' => ['entry_id'], 'expenses' => ['entry_id'],
            'commission_transactions' => ['received_entry_id', 'settled_entry_id'],
            'invoices' => ['entry_id'], 'bills' => ['entry_id'], 'credit_notes' => ['entry_id'], 'credit_refunds' => ['entry_id'],
            'stock_adjustments' => ['entry_id'], 'wage_sheets' => ['entry_id', 'paid_entry_id'], 'stock_moves' => ['entry_id'],
            'pay_runs' => ['entry_id'], 'pay_payments' => ['entry_id'], 'pay_advances' => ['entry_id'],
        ];
        $parts = [];
        foreach ($sources as $table => $cols) {
            foreach ($cols as $c) {
                // companies only have the tables of the modules they use
                if (Tables::has($table, $c)) {
                    $parts[] = "SELECT `$c` FROM `$table` WHERE `$c` IS NOT NULL";
                }
            }
        }

        return $parts ? implode(' UNION ', $parts) : 'SELECT NULL';
    }

    /** Hand-made entry types for the list filter: label => friendly name. */
    public function listTypes(): array
    {
        $out = [];
        foreach (EntryType::whereNotIn('label', ['sales_invoice', 'purchase_bill'])->orderBy('id')->get() as $t) {
            $out[$t->label] = $this->typeName($t);
        }

        return $out;
    }

    /**
     * Newest first, 50 a page. Each row gets `from` / `to`: the accounts credited and debited.
     * Unless `all` is set, entries made by other screens (invoices, payments ...) are left out.
     */
    public function paginate(array $f, int $page, int $per = 50): array
    {
        $db = DB::connection('tenant');
        $types = $this->listTypes();
        $q = $db->table('entries')->join('entrytypes', 'entrytypes.id', '=', 'entries.entrytype_id');

        if (empty($f['all'])) {
            $q->whereIn('entrytypes.label', array_keys($types))->whereRaw('entries.id NOT IN ('.$this->autoPostedSql().')');
        }
        if (! empty($f['account'])) {
            $q->whereRaw('entries.id IN (SELECT entry_id FROM entryitems WHERE ledger_id = ?)', [(int) $f['account']]);
        }
        $q->when(! empty($f['tag']), fn ($x) => $x->where('entries.tag_id', (int) $f['tag']))
            ->when(($f['min'] ?? '') !== '', fn ($x) => $x->where('entries.dr_total', '>=', $f['min']))
            ->when(($f['max'] ?? '') !== '', fn ($x) => $x->where('entries.dr_total', '<=', $f['max']))
            ->when(! empty($f['type']), fn ($x) => $x->where('entrytypes.label', $f['type']))
            ->when(! empty($f['from']), fn ($x) => $x->where('entries.date', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($x) => $x->where('entries.date', '<=', $f['to']));

        if (($term = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $num = preg_replace('/\D+/', '', $term);
            $q->where(function ($w) use ($like, $num, $term) {
                $w->where('entries.notes', 'like', $like)
                    ->orWhereRaw("entries.id IN (SELECT ei.entry_id FROM entryitems ei JOIN ledgers l ON l.id = ei.ledger_id WHERE l.name LIKE ? ESCAPE '!' OR ei.narration LIKE ? ESCAPE '!')", [$like, $like]);
                if ($num !== '' && $num === trim($term, ' #')) {
                    $w->orWhere('entries.number', (int) $num);
                }
            });
        }

        $total = (clone $q)->count();
        $rows = $q->orderByDesc('entries.date')->orderByDesc('entries.id')->forPage($page, $per)
            ->get(['entries.*', 'entrytypes.label as type_label', 'entrytypes.name as type_name', 'entrytypes.prefix', 'entrytypes.suffix', 'entrytypes.zero_padding']);

        $sides = [];
        if ($rows->isNotEmpty()) {
            $parties = $this->partyMap();
            $items = $db->table('entryitems as ei')->leftJoin('ledgers as l', 'l.id', '=', 'ei.ledger_id')
                ->whereIn('ei.entry_id', $rows->pluck('id'))->orderBy('ei.id')->get(['ei.entry_id', 'ei.dc', 'ei.ledger_id', 'l.name']);
            foreach ($items as $it) {
                $label = isset($parties[$it->ledger_id]) ? $parties[$it->ledger_id]['account'].' - '.$parties[$it->ledger_id]['name'] : ($it->name ?? '(deleted account)');
                $sides[$it->entry_id][$it->dc === 'D' ? 'to' : 'from'][$label] = true;
            }
        }

        return ['rows' => $rows, 'sides' => $sides, 'total' => $total, 'pages' => max(1, (int) ceil($total / $per))];
    }

    /** Customers' and vendors' own accounts: ledger id => [side, name, account (group name)]. */
    public function partyMap(): array
    {
        static $cache = [];
        $key = config('database.connections.tenant.database');
        if (! isset($cache[$key])) {
            $cache[$key] = [];
            foreach (['customers' => 'ar', 'vendors' => 'ap'] as $table => $side) {
                $rows = DB::connection('tenant')->table($table)->leftJoin('ledgers', 'ledgers.id', '=', "$table.ledger_id")
                    ->leftJoin('groups', 'groups.id', '=', 'ledgers.group_id')->get(["$table.ledger_id", "$table.name", 'groups.name as gname', 'ledgers.group_id']);
                foreach ($rows as $r) {
                    $cache[$key][(int) $r->ledger_id] = ['side' => $side, 'name' => $r->name, 'group_id' => (int) $r->group_id,
                        'account' => $r->gname ?: ($side === 'ar' ? 'Accounts Receivable' : 'Accounts Payable')];
                }
            }
        }

        return $cache[$key];
    }

    // ---- who made this entry ------------------------------------------------

    /** The document that owns this entry, if another screen made it: ['label' => ...] or null. Such entries change only through that document. */
    public function linkedDocument(int $entryId): ?array
    {
        $checks = [
            ['invoices', 'entry_id', 'invoice'], ['payments', 'entry_id', 'customer payment'], ['bills', 'entry_id', 'bill'],
            ['vendor_payments', 'entry_id', 'vendor payment'], ['expenses', 'entry_id', 'quick expense'], ['credit_notes', 'entry_id', 'credit note'],
            ['credit_refunds', 'entry_id', 'refund'], ['stock_adjustments', 'entry_id', 'stock adjustment'], ['wage_sheets', 'entry_id', 'wage sheet'],
            ['wage_sheets', 'paid_entry_id', 'wage sheet payment'], ['stock_moves', 'entry_id', 'site stock movement'],
            ['commission_transactions', 'received_entry_id', 'commission'], ['commission_transactions', 'settled_entry_id', 'commission settlement'],
            ['pay_runs', 'entry_id', 'pay run'], ['pay_payments', 'entry_id', 'salary payment'], ['pay_advances', 'entry_id', 'salary advance or loan'],
        ];
        foreach ($checks as [$table, $col, $label]) {
            if (Tables::has($table, $col) && DB::connection('tenant')->table($table)->where($col, $entryId)->exists()) {
                return ['label' => $label];
            }
        }

        return null;
    }

    public function closedPeriodError(string|array|null $dates): ?string
    {
        $lock = Setting::first()?->lock_date;
        if (empty($lock) || (string) $lock === '0000-00-00') {
            return null;
        }
        $lock = substr((string) $lock, 0, 10);
        foreach ((array) $dates as $d) {
            if ($d && substr((string) $d, 0, 10) <= $lock) {
                return 'The books are closed up to '.Money::date($lock).', so nothing dated on or before then can be added, changed, voided or deleted. An administrator can change this under Settings → Close Books.';
            }
        }

        return null;
    }

    // ---- the account picker ------------------------------------------------

    /**
     * Accounts a line may use, in chart order, for an entry type's restriction.
     * Each option: v (value), t (text), d (depth), g (true for a group heading). Customers and vendors
     * appear once, as "Accounts Receivable" / "Accounts Payable" (value party:ar / party:ap) - the form then asks which one.
     */
    public function accountOptions(int $restriction): array
    {
        $groups = Group::orderBy('code')->orderBy('id')->get();
        $ledgers = Ledger::orderBy('code')->orderBy('id')->get()->groupBy('group_id');
        $kids = $groups->groupBy(fn ($g) => (int) $g->parent_id);
        $parties = $this->partyMap();
        $out = [];

        $qualifies = function (Ledger $l) use ($restriction, $parties) {
            if (isset($parties[$l->id])) {
                return false;
            }

            return match ($restriction) { 4 => (int) $l->type === 1, 5 => (int) $l->type !== 1, default => true };
        };

        // party lines are offered as one "Accounts Receivable / Payable" choice when the entry type allows any account on that side
        $partyOk = $restriction <= 3 || $restriction === 5;

        // does the group (or anything under it) hold something that may be picked?
        $hasAny = function ($g) use (&$hasAny, $kids, $ledgers, $qualifies, $parties, $partyOk) {
            foreach ($ledgers->get($g->id, []) as $l) {
                if ($qualifies($l) || (isset($parties[$l->id]) && $partyOk)) {
                    return true;
                }
            }
            foreach ($kids->get($g->id, []) as $c) {
                if ($hasAny($c)) {
                    return true;
                }
            }

            return false;
        };

        $walk = function ($g, int $depth) use (&$walk, &$out, $kids, $ledgers, $qualifies, $parties, $partyOk, $hasAny) {
            if (! $hasAny($g)) {
                return;
            }
            $out[] = ['v' => (string) (-1 * (int) $g->id), 't' => $g->name, 'd' => $depth, 'g' => true];

            $partyOffered = [];
            foreach ($ledgers->get($g->id, []) as $l) {
                if (isset($parties[$l->id])) {
                    $side = $parties[$l->id]['side'];
                    if (! isset($partyOffered[$side]) && $partyOk) {
                        $out[] = ['v' => 'party:'.$side, 't' => $parties[$l->id]['account'], 'd' => $depth + 1, 'g' => false];
                        $partyOffered[$side] = true;
                    }

                    continue;
                }
                if ($qualifies($l)) {
                    $out[] = ['v' => (string) $l->id, 't' => trim(($l->code ? $l->code.' - ' : '').$l->name), 'd' => $depth + 1, 'g' => false];
                }
            }
            foreach ($kids->get($g->id, []) as $c) {
                $walk($c, $depth + 1);
            }
        };
        foreach ($kids->get(0, []) as $root) {
            $walk($root, 0);
        }

        return $out;
    }

    /** Customers and vendors for the "which one?" box: side => [label, who, list of [ledger id, name]]. */
    public function partyChoices(): array
    {
        $out = ['ar' => ['label' => 'Accounts Receivable', 'who' => 'customer', 'list' => []], 'ap' => ['label' => 'Accounts Payable', 'who' => 'vendor', 'list' => []]];
        foreach ($this->partyMap() as $lid => $p) {
            $out[$p['side']]['label'] = $p['account'];
            $out[$p['side']]['list'][] = [(int) $lid, $p['name']];
        }
        foreach ($out as $k => $v) {
            usort($out[$k]['list'], fn ($a, $b) => strnatcasecmp($a[1], $b[1]));
        }

        return $out;
    }

    /** Ledger id => the side its balance normally sits on ('D'/'C'), contra accounts flipped. */
    public function normalSides(): array
    {
        $types = $this->chart->groupOptions();
        $out = [];
        foreach (Ledger::get(['id', 'group_id', 'is_contra']) as $l) {
            $n = ChartOfAccounts::normalSide($types[$l->group_id]['type'] ?? 'Asset');
            $out[$l->id] = $l->is_contra ? ($n === 'D' ? 'C' : 'D') : $n;
        }

        return $out;
    }

    // ---- project tags (Construction) ---------------------------------------

    public function constructionOn(): bool
    {
        return (bool) Setting::first()?->construction_enabled;
    }

    /** Grouped "project → cost type" options: label => [value => text]; null when Construction is off. */
    public function projectOptions(array $keepIds = []): ?array
    {
        if (! $this->constructionOn() || ! Tables::has('projects')) {
            return null;
        }
        $keep = array_filter(array_map('intval', $keepIds));
        $rows = DB::connection('tenant')->table('projects')
            ->where(fn ($q) => $q->whereIn('status', ['active', 'on_hold'])->when($keep, fn ($x) => $x->orWhereIn('id', $keep)))
            ->orderByDesc('number')->get(['id', 'number', 'name']);
        $out = [];
        foreach ($rows as $p) {
            $g = [];
            foreach (self::COST_TYPES as $k => $label) {
                $g["{$p->id}:$k"] = $label;
            }
            $out["#{$p->number} {$p->name}"] = $g;
        }

        return $out;
    }

    /** "12:materials" -> [12, 'materials'] or [null, null]; an unknown project or cost type is dropped. */
    protected function parseTag($value): array
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0' || ! $this->constructionOn() || ! Tables::has('projects')) {
            return [null, null];
        }
        [$pid, $type] = array_pad(explode(':', $value, 2), 2, '');
        if ((int) $pid <= 0 || ! array_key_exists($type, self::COST_TYPES) || ! DB::connection('tenant')->table('projects')->where('id', (int) $pid)->exists()) {
            return [null, null];
        }

        return [(int) $pid, $type];
    }

    // ---- saving ------------------------------------------------------------

    /**
     * Check and tidy posted lines. Each line is ['account' => id|party:ar|party:ap, 'party' => ledger id,
     * 'debit', 'credit', 'narration', 'project']. Empty lines are dropped. Throws a ValidationException (key `lines`).
     *
     * @return array<int, array{dc: string, ledger_id: int, amount: string, narration: string, project_id: ?int, cost_type: ?string}>
     */
    public function normalizeLines(array $posted, EntryType $type, int $decimals): array
    {
        $clean = fn ($v) => str_replace([',', ' '], '', trim((string) $v));
        $lines = [];

        foreach ($posted as $row) {
            if (! is_array($row)) {
                continue;
            }
            $dr = $clean($row['debit'] ?? '');
            $cr = $clean($row['credit'] ?? '');
            $account = trim((string) ($row['account'] ?? ''));
            $party = trim((string) ($row['party'] ?? ''));

            if (($dr !== '' && ! is_numeric($dr)) || ($cr !== '' && ! is_numeric($cr))) {
                $this->fail('Amounts must be numbers, like 1250 or 1,250.50.');
            }
            $hasDr = $dr !== '' && (float) $dr != 0;
            $hasCr = $cr !== '' && (float) $cr != 0;

            if ($account === '' || $account === '0') {
                if ($hasDr || $hasCr) {
                    $this->fail('Choose an account on every line that has an amount.');
                }

                continue;
            }
            if (! $hasDr && ! $hasCr) {
                continue;
            }
            if ($hasDr && $hasCr) {
                $this->fail('A line can have a Debit or a Credit, not both. Put them on separate lines.');
            }

            // Accounts Receivable / Payable: the line really posts to the chosen customer's or vendor's own account
            if (str_starts_with($account, 'party:')) {
                $map = $this->partyMap();
                if (! ctype_digit($party) || ! isset($map[(int) $party]) || $map[(int) $party]['side'] !== substr($account, 6)) {
                    $this->fail('Choose the customer or vendor on every Accounts Receivable / Accounts Payable line.');
                }
                $account = $party;
            }
            if (! ctype_digit($account) || (int) $account <= 0) {
                $this->fail('Choose an account on every line that has an amount.');
            }

            $amount = $hasDr ? $dr : $cr;
            $pattern = $decimals > 0 ? '/^\d+(\.\d{1,'.$decimals.'})?$/' : '/^\d+$/';
            if (! preg_match($pattern, $amount)) {
                $this->fail("Amounts can have at most $decimals decimal place".($decimals === 1 ? '' : 's').' and cannot be negative.');
            }
            [$pid, $cost] = $this->parseTag($row['project'] ?? '');
            $lines[] = ['dc' => $hasDr ? 'D' : 'C', 'ledger_id' => (int) $account, 'amount' => Money::add($amount, 0), 'narration' => trim((string) ($row['narration'] ?? '')), 'project_id' => $pid, 'cost_type' => $cost];
        }

        if (count($lines) < 2) {
            $this->fail('Enter at least two lines: what is debited and what is credited.');
        }
        $this->checkRules($lines, $type);

        return $lines;
    }

    /** Debits = credits, accounts exist, and the entry type's bank/cash restriction holds. */
    protected function checkRules(array $lines, EntryType $type): void
    {
        $ledgers = Ledger::whereIn('id', array_column($lines, 'ledger_id'))->get()->keyBy('id');
        $dr = '0.00';
        $cr = '0.00';
        $bankOnDebit = $bankOnCredit = false;
        $r = (int) $type->restriction_bankcash;

        foreach ($lines as $l) {
            $ledger = $ledgers->get($l['ledger_id']);
            if (! $ledger) {
                $this->fail('One of the accounts no longer exists. Choose it again.');
            }
            $bank = (int) $ledger->type === 1;
            if ($r === 4 && ! $bank) {
                $this->fail('This type of entry can only use bank or cash accounts.');
            }
            if ($r === 5 && $bank) {
                $this->fail('This type of entry cannot use bank or cash accounts.');
            }
            if ($l['dc'] === 'D') {
                $dr = Money::add($dr, $l['amount']);
                $bankOnDebit = $bankOnDebit || $bank;
            } else {
                $cr = Money::add($cr, $l['amount']);
                $bankOnCredit = $bankOnCredit || $bank;
            }
        }
        if (Money::cmp($dr, $cr) !== 0) {
            $this->fail('Total debits ('.Money::format($dr).') must equal total credits ('.Money::format($cr).').');
        }
        if ($r === 2 && ! $bankOnDebit) {
            $this->fail('This type of entry needs at least one bank or cash account on the debit side.');
        }
        if ($r === 3 && ! $bankOnCredit) {
            $this->fail('This type of entry needs at least one bank or cash account on the credit side.');
        }
    }

    /**
     * Create ($entry null) or replace an entry and all its lines together or not at all.
     *
     * @param  array  $header  date (Y-m-d), tag_id, notes, number
     */
    public function save(EntryType $type, ?Entry $entry, array $header, array $lines): Entry
    {
        $number = $this->resolveNumber($type, $entry, $header['number'] ?? null);
        $dr = array_reduce(array_filter($lines, fn ($l) => $l['dc'] === 'D'), fn ($c, $l) => Money::add($c, $l['amount']), '0.00');

        return DB::connection('tenant')->transaction(function () use ($type, $entry, $header, $lines, $number, $dr) {
            $attrs = [
                'entrytype_id' => $type->id, 'number' => $number, 'tag_id' => ! empty($header['tag_id']) ? (int) $header['tag_id'] : null,
                'date' => $header['date'], 'notes' => (string) ($header['notes'] ?? ''), 'dr_total' => $dr, 'cr_total' => $dr,
            ];
            if ($entry) {
                $entry->update($attrs);
                DB::connection('tenant')->table('entryitems')->where('entry_id', $entry->id)->delete();
            } else {
                $entry = Entry::create($attrs);
            }
            // older company databases have no project columns on entry lines
            $withProjects = Tables::has('entryitems', 'project_id') && Tables::has('entryitems', 'cost_type');
            DB::connection('tenant')->table('entryitems')->insert(array_map(
                fn ($l) => ($withProjects ? $l : array_diff_key($l, ['project_id' => 1, 'cost_type' => 1])) + ['entry_id' => $entry->id], $lines));

            return $entry;
        });
    }

    /** The number to use: given, or the next free one for auto-numbered types. A given number must be unused in its type. */
    protected function resolveNumber(EntryType $type, ?Entry $entry, $given): ?int
    {
        $given = trim((string) $given);
        if ($given !== '') {
            if (! ctype_digit($given)) {
                $this->fail('The entry number must be a whole number.', 'number');
            }
            if (Entry::where('entrytype_id', $type->id)->where('number', (int) $given)->when($entry, fn ($q) => $q->where('id', '!=', $entry->id))->exists()) {
                $this->fail('That entry number is already used by another entry of this type.', 'number');
            }

            return (int) $given;
        }
        if ($entry) {
            return $entry->number;
        }
        if ((int) $type->numbering === 2) {
            $this->fail('This type of entry needs a number.', 'number');
        }

        return (int) $type->numbering === 1 ? $this->nextNumber($type->id) : null;
    }

    public function delete(Entry $entry): void
    {
        if ($doc = $this->linkedDocument($entry->id)) {
            $this->fail('This entry belongs to '.(preg_match('/^[aeiou]/i', $doc['label']) ? 'an ' : 'a ').$doc['label'].' - delete or void it from there instead of from Entries, so its own records stay consistent.', 'entry');
        }
        if ($err = $this->closedPeriodError($entry->date?->toDateString())) {
            $this->fail($err, 'entry');
        }
        DB::connection('tenant')->transaction(function () use ($entry) {
            DB::connection('tenant')->table('entryitems')->where('entry_id', $entry->id)->delete();
            $entry->delete();
        });
    }

    // ---- quick screens -----------------------------------------------------

    /** Move money between two of your own bank/cash accounts: Dr the destination, Cr the source. */
    public function transfer(int $fromId, int $toId, string $date, string $amount, ?string $memo): Entry
    {
        $this->guard($date);
        if ($fromId === $toId) {
            $this->fail('Choose two different accounts to transfer between.', 'from_ledger_id');
        }
        $from = Ledger::find($fromId);
        $to = Ledger::find($toId);
        if (! $from || (int) $from->type !== 1 || ! $to || (int) $to->type !== 1) {
            $this->fail('Both accounts must be Bank/Cash accounts.', 'from_ledger_id');
        }
        $narration = trim((string) $memo) !== '' ? trim($memo) : "Transfer from {$from->name} to {$to->name}";

        $entry = $this->quick($this->typeByLabel('contra'), $date, $amount, $narration, $toId, $fromId);
        ActivityLog::add('Transferred '.Money::format($amount)." from {$from->name} to {$to->name}");

        return $entry;
    }

    /** Money in (Dr bank, Cr category) or out (Dr category, Cr bank), using the Receipt / Payment types. */
    public function simple(string $direction, int $bankId, int $categoryId, string $date, string $amount, ?string $memo, ?string $projectTag = null): Entry
    {
        $this->guard($date);
        $bank = Ledger::find($bankId);
        $category = Ledger::find($categoryId);
        if (! $bank || (int) $bank->type !== 1) {
            $this->fail('Choose a Bank/Cash account.', 'bank_ledger_id');
        }
        if (! $category || (int) $category->type !== 0) {
            $this->fail('Choose a category account (not a Bank/Cash account).', 'category_ledger_id');
        }
        $in = $direction === 'in';
        $narration = trim((string) $memo) !== '' ? trim($memo) : ($in ? "Received into {$bank->name} for {$category->name}" : "Paid from {$bank->name} for {$category->name}");

        $entry = $this->quick($this->typeByLabel($in ? 'receipt' : 'payment'), $date, $amount, $narration, $in ? $bankId : $categoryId, $in ? $categoryId : $bankId);

        // Construction: put the project on the expense (debit) line of a Money Out
        [$pid, $cost] = $in ? [null, null] : $this->parseTag($projectTag);
        if ($pid) {
            DB::connection('tenant')->table('entryitems')->where('entry_id', $entry->id)->where('ledger_id', $categoryId)->where('dc', 'D')->update(['project_id' => $pid, 'cost_type' => $cost]);
        }
        ActivityLog::add('Recorded '.Money::format($amount).($in ? " received into {$bank->name}" : " paid from {$bank->name}")." for {$category->name}");

        return $entry;
    }

    protected function quick(EntryType $type, string $date, string $amount, string $narration, int $drLedger, int $crLedger): Entry
    {
        if (! Money::positive($amount)) {
            $this->fail('Enter an amount greater than zero.', 'amount');
        }
        $amount = Money::add($amount, 0);

        return DB::connection('tenant')->transaction(function () use ($type, $date, $amount, $narration, $drLedger, $crLedger) {
            $entry = Entry::create(['entrytype_id' => $type->id, 'tag_id' => null, 'number' => $this->nextNumber($type->id), 'date' => $date,
                'dr_total' => $amount, 'cr_total' => $amount, 'notes' => $narration]);
            DB::connection('tenant')->table('entryitems')->insert([
                ['entry_id' => $entry->id, 'ledger_id' => $drLedger, 'amount' => $amount, 'dc' => 'D', 'narration' => $narration],
                ['entry_id' => $entry->id, 'ledger_id' => $crLedger, 'amount' => $amount, 'dc' => 'C', 'narration' => $narration],
            ]);

            return $entry;
        });
    }

    /** Posting is refused while the company account is locked or the date falls in a closed period. */
    public function guard(string $date): void
    {
        if ($this->chart->companyLocked()) {
            $this->fail('This account is locked. Unlock it under Account Settings before recording transactions.', 'date');
        }
        if ($err = $this->closedPeriodError($date)) {
            $this->fail($err, 'date');
        }
    }

    protected function fail(string $message, string $key = 'lines'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
