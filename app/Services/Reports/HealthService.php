<?php

namespace App\Services\Reports;

use App\Services\Accounting\ChartOfAccounts;
use App\Services\Settings\BackupService;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Support\Facades\DB;

/**
 * Books Health Check (legacy Health_model): one page that looks for the things that make a report look wrong and says in plain words what
 * each one means and where to fix it. Read-only apart from the one repair (stale saved entry totals). run() returns findings:
 * ['level' => problem|warning|info, 'group' => books|parties|documents|stock|safety, 'title', 'why', 'rows' => [[label, amount|null, url|null, note|null]], 'more', 'action'].
 * A check with nothing to report adds nothing, so an empty result means the books look healthy.
 */
class HealthService
{
    public const LIST_MAX = 10;

    public const DRAFT_DAYS = 14;

    public const BACKUP_DAYS = 30;

    protected array $findings = [];

    public function __construct(protected ChartOfAccounts $chart) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    protected function add(string $level, string $group, string $title, string $why, array $rows = [], int $more = 0, ?array $action = null): void
    {
        $this->findings[] = compact('level', 'group', 'title', 'why', 'rows', 'more', 'action');
    }

    protected function money($v): string
    {
        return Money::format(ltrim((string) Money::add($v, 0), '-'));
    }

    protected function nz($v): bool
    {
        return ! Money::isZero($v);
    }

    public function run(bool $isAdmin = false): array
    {
        $this->findings = [];
        $today = now()->toDateString();
        $this->checkEntriesBalance();
        $this->checkOpeningDifference();
        $this->checkUnposted();
        $this->checkWrongSide($this->ledgerBalances());
        $this->checkFutureDates($today);
        $this->checkUnapplied('ar');
        $this->checkUnapplied('ap');
        $this->checkOverdue('ar', $today);
        $this->checkOverdue('ap', $today);
        $this->checkOldDrafts($today);
        $this->checkNegativeStock();
        if ($isAdmin) {
            $this->checkBackup($today);
        }

        return $this->findings;
    }

    protected function entryRow(object $e, array $types, ?string $note = null): array
    {
        $et = $types[(int) $e->entrytype_id] ?? null;

        return [($et->name ?? 'Entry').' #'.$e->number.' - '.Money::date($e->date), $e->amount ?? null, $et ? route('entries.show', [$et->label, $e->id]) : null, $note];
    }

    // ---- the books themselves ----------------------------------------------------------------------------------------------------

    /** Entries whose lines don't balance, or whose saved dr/cr totals differ from their lines. */
    protected function mismatched(): array
    {
        return $this->db()->select("SELECT x.id, x.number, x.date, x.entrytype_id, x.dr_total, x.cr_total,
                COALESCE(SUM(CASE WHEN y.dc = 'D' THEN y.amount ELSE 0 END), 0) AS d, COALESCE(SUM(CASE WHEN y.dc = 'C' THEN y.amount ELSE 0 END), 0) AS c
            FROM {$this->p('entries')} x LEFT JOIN {$this->p('entryitems')} y ON y.entry_id = x.id
            GROUP BY x.id, x.number, x.date, x.entrytype_id, x.dr_total, x.cr_total
            HAVING ROUND(d - c, 2) <> 0 OR ROUND(x.dr_total - d, 2) <> 0 OR ROUND(x.cr_total - c, 2) <> 0
            ORDER BY x.date DESC, x.id DESC");
    }

    protected function p(string $table): string
    {
        return $this->db()->getTablePrefix().$table;
    }

    protected function checkEntriesBalance(): void
    {
        $rows = $this->mismatched();
        if (! $rows) {
            return;
        }
        $types = $this->db()->table('entrytypes')->get(['id', 'label', 'name'])->keyBy('id')->all();
        $broken = [];
        $header = [];
        foreach ($rows as $r) {
            $diff = Money::sub($r->d, $r->c);
            $this->nz($diff) ? $broken[] = [$r, $diff] : $header[] = $r;
        }
        if ($broken) {
            $list = array_map(fn ($b) => $this->entryRow($b[0], $types, 'debits and credits differ by '.$this->money($b[1])), array_slice($broken, 0, self::LIST_MAX));
            $this->add('problem', 'books', count($broken) === 1 ? "1 journal entry doesn't balance" : count($broken)." journal entries don't balance",
                "Every entry must have equal debits and credits. One that doesn't makes the Trial Balance and Balance Sheet wrong. Open it, correct the amounts and save it again.", $list, max(0, count($broken) - self::LIST_MAX));
        }
        if ($header) {
            $n = count($header);
            $list = array_map(fn ($r) => $this->entryRow($r, $types, 'shows '.$this->money($r->dr_total).', its lines add up to '.$this->money($r->d)), array_slice($header, 0, self::LIST_MAX));
            $this->add('warning', 'books', $n === 1 ? '1 entry shows the wrong total' : "$n entries show the wrong total",
                'The lines of '.($n === 1 ? 'this entry' : 'these entries').' balance and the reports are right - only the total saved on the entry itself is out of date (left over from an older version of Nidaam), so lists show the wrong amount. Repair sets the total to what the lines add up to. No amount or account changes.',
                $list, max(0, $n - self::LIST_MAX), ['label' => 'Repair '.($n === 1 ? 'this total' : "these $n totals"), 'url' => route('reports.health-repair'), 'confirm' => 'Set the saved total of '.($n === 1 ? 'this entry' : "these $n entries").' to what the lines add up to? No amounts or accounts change.']);
        }
    }

    /** Entries whose lines balance but whose saved totals are stale get those two totals set from their lines. Returns how many were corrected. */
    public function repairEntryTotals(): int
    {
        $fixed = 0;
        foreach ($this->mismatched() as $r) {
            if ($this->nz(Money::sub($r->d, $r->c)) || ! Money::positive($r->d)) {
                continue;
            }
            $this->db()->table('entries')->where('id', $r->id)->update(['dr_total' => $r->d, 'cr_total' => $r->c]);
            $fixed++;
        }

        return $fixed;
    }

    protected function checkOpeningDifference(): void
    {
        $r = $this->db()->selectOne("SELECT COALESCE(SUM(CASE WHEN op_balance_dc = 'D' THEN op_balance ELSE 0 END), 0) AS d, COALESCE(SUM(CASE WHEN op_balance_dc = 'C' THEN op_balance ELSE 0 END), 0) AS c FROM {$this->p('ledgers')}");
        $diff = Money::sub($r->d, $r->c);
        if (! $this->nz($diff)) {
            return;
        }
        $this->add('problem', 'books', 'Opening balances are out by '.$this->money($diff),
            "The opening balances typed into the Chart of Accounts don't add up: debits are ".Money::format($r->d).' and credits are '.Money::format($r->c).'. The Balance Sheet shows this as a "difference in opening balance". Check each account\'s opening balance, or put the difference into an Opening Balance equity account.',
            [['Chart of Accounts', null, route('chart'), null]]);
    }

    /** Issued invoices / bills and received payments with no journal entry: they are not in the reports at all. */
    protected function checkUnposted(): void
    {
        $sets = [['invoices', 'issued', 'INV-', 'invoices.show', 'invoice'], ['bills', 'issued', 'BILL-', 'bills.show', 'bill'], ['payments', 'received', 'PMT-', 'payments.show', 'customer payment'], ['vendor_payments', 'paid', 'VPMT-', 'vendor-payments.show', 'vendor payment']];
        $list = [];
        $count = 0;
        foreach ($sets as [$table, $status, $prefix, $route, $name]) {
            if (! Tables::has($table, 'entry_id')) {
                continue;
            }
            $amount = Tables::has($table, 'total') ? 'total' : 'amount';
            $rows = $this->db()->select("SELECT d.id, d.number, d.$amount AS amount FROM {$this->p($table)} d LEFT JOIN {$this->p('entries')} e ON e.id = d.entry_id WHERE d.status = ? AND e.id IS NULL ORDER BY d.date DESC, d.id DESC", [$status]);
            $count += count($rows);
            foreach ($rows as $r) {
                if (count($list) < self::LIST_MAX) {
                    $list[] = [$prefix.str_pad((string) $r->number, 4, '0', STR_PAD_LEFT), $r->amount, route($route, $r->id), ucfirst($name).' with no journal entry'];
                }
            }
        }
        if (! $count) {
            return;
        }
        $this->add('problem', 'books', $count === 1 ? '1 document is missing from the books' : "$count documents are missing from the books",
            "These are marked as issued or paid but have no journal entry, so they don't show in any report or balance. Open each one and check it; entering a corrected copy (and voiding or deleting the broken one) puts it into the books.", $list, max(0, $count - self::LIST_MAX));
    }

    /** Every account's balance (opening + all entries) with its class in the chart and whether it belongs to a customer or vendor. */
    protected function ledgerBalances(): array
    {
        $contra = Tables::has('ledgers', 'is_contra') ? 'l.is_contra' : '0';
        $rows = $this->db()->select("SELECT l.id, l.name, l.code, l.group_id, l.type, l.op_balance, l.op_balance_dc, $contra AS is_contra,
                COALESCE(SUM(CASE WHEN i.dc = 'D' THEN i.amount ELSE 0 END), 0) AS d, COALESCE(SUM(CASE WHEN i.dc = 'C' THEN i.amount ELSE 0 END), 0) AS c
            FROM {$this->p('ledgers')} l LEFT JOIN {$this->p('entryitems')} i ON i.ledger_id = l.id GROUP BY l.id, l.name, l.code, l.group_id, l.type, l.op_balance, l.op_balance_dc, is_contra");
        $parents = $this->db()->table('groups')->pluck('parent_id', 'id')->all();
        $classOfTop = [];
        foreach (['asset' => 'D', 'liability' => 'C', 'income' => 'C', 'expense' => 'D'] as $kind => $side) {
            if ($id = $this->chart->topLevelGroupId($kind)) {
                $classOfTop[(int) $id] = [$kind, $side];
            }
        }
        $topOf = function ($gid) use ($parents) {
            for ($i = 0; isset($parents[$gid]) && $parents[$gid] !== null && (int) $parents[$gid] !== 0 && $i < 50; $i++) {
                $gid = (int) $parents[$gid];
            }

            return (int) $gid;
        };
        $customers = $this->db()->table('customers')->get(['id', 'name', 'ledger_id'])->keyBy('ledger_id');
        $vendors = $this->db()->table('vendors')->get(['id', 'name', 'ledger_id'])->keyBy('ledger_id');
        $out = [];
        foreach ($rows as $r) {
            $op = $r->op_balance_dc === 'C' ? Money::neg($r->op_balance) : Money::add($r->op_balance, 0);
            $top = $topOf((int) $r->group_id);
            $r->net = Money::sub(Money::add($op, $r->d), $r->c); // + = debit
            $r->class = $classOfTop[$top][0] ?? null;
            $r->normal = $classOfTop[$top][1] ?? null;
            $r->customer = $customers->get($r->id);
            $r->vendor = $vendors->get($r->id);
            $out[] = $r;
        }

        return $out;
    }

    /** Accounts sitting on the opposite side from normal: the red rows in the reports. */
    protected function checkWrongSide(array $balances): void
    {
        $cash = $custCredit = $vendDebit = $other = [];
        $invLedger = 0;
        $negStock = 0;
        if (Tables::has('item_settings')) {
            $invLedger = (int) ($this->db()->table('item_settings')->where('id', 1)->value('inventory_asset_ledger_id') ?? 0);
            $negStock = (int) $this->db()->table('items')->where('type', 'inventory')->where('quantity_on_hand', '<', 0)->count();
        }
        foreach ($balances as $b) {
            if ($b->normal === null || ! empty($b->is_contra) || ! $this->nz($b->net)) {
                continue;
            }
            $isDebit = Money::positive($b->net);
            if (($b->normal === 'D') ? $isDebit : ! $isDebit) {
                continue;
            }
            $name = trim(($b->code ? $b->code.' ' : '').$b->name);
            $url = route('reports.statement', ['account' => $b->id]);
            if ($b->customer) {
                $custCredit[] = [$b->customer->name, $b->net, route('customers.show', $b->customer->id), 'you owe them this'];
            } elseif ($b->vendor) {
                $vendDebit[] = [$b->vendor->name, $b->net, route('vendors.show', $b->vendor->id), 'they owe you this'];
            } elseif ((int) $b->type === 1 && $b->class === 'asset') {
                $cash[] = [$name, $b->net, $url, 'below zero'];
            } else {
                $note = ucfirst($b->class).' account with a '.($isDebit ? 'debit' : 'credit').' balance';
                if ((int) $b->id === $invLedger && ! $isDebit && $negStock > 0) {
                    $note = 'because stock was sold below zero - fix the item'.($negStock === 1 ? '' : 's').' under "Needs fixing" and this clears too';
                }
                $other[] = [$name, $b->net, $url, $note];
            }
        }
        $cut = fn ($l) => array_slice($l, 0, self::LIST_MAX);
        $more = fn ($l) => max(0, count($l) - self::LIST_MAX);
        if ($cash) {
            $this->add('problem', 'books', count($cash) === 1 ? 'A cash or bank account is below zero' : count($cash).' cash or bank accounts are below zero',
                "A till or bank account can't hold less than nothing, so money going out was recorded but some money coming in wasn't - usually a missing sale, customer payment, deposit or transfer. Open the account's statement and compare it with the real bank statement.", $cut($cash), $more($cash));
        }
        if ($vendDebit) {
            $this->add('warning', 'parties', count($vendDebit) === 1 ? '1 vendor owes you money' : count($vendDebit).' vendors owe you money',
                'You paid these vendors more than the bills entered for them. This is why Accounts Payable shows in red, in (brackets), on the Balance Sheet. If it was an advance, enter the bill when it comes and apply the payment to it. If a bill was never entered, enter it now. If the payment was a mistake or entered twice, delete it.', $cut($vendDebit), $more($vendDebit));
        }
        if ($custCredit) {
            $this->add('warning', 'parties', count($custCredit) === 1 ? '1 customer has paid you more than invoiced' : count($custCredit).' customers have paid you more than invoiced',
                'These customers have credit with you: they paid more than the invoices entered, or have a credit note left over. If an invoice is missing, enter it and apply the payment. If they will buy more, the credit can be applied to their next invoice. If you gave the money back, record it as a refund.', $cut($custCredit), $more($custCredit));
        }
        if ($other) {
            $this->add('warning', 'books', count($other) === 1 ? '1 account has the opposite of its normal balance' : count($other).' accounts have the opposite of their normal balance',
                "These show in red, in (brackets), on the Balance Sheet or Profit & Loss. It usually means something was posted to the wrong account, or debit and credit were swapped. Open the account's statement to find the entry. (If an account is meant to run this way - like Owner's Drawings or Accumulated Depreciation - mark it as a contra account in the Chart of Accounts and it will stop showing here.)", $cut($other), $more($other));
        }
    }

    protected function checkFutureDates(string $today): void
    {
        $rows = $this->db()->table('entries')->where('date', '>', $today)->orderByDesc('date')->orderByDesc('id')->get(['id', 'number', 'date', 'entrytype_id', 'dr_total as amount'])->all();
        if (! $rows) {
            return;
        }
        $types = $this->db()->table('entrytypes')->get(['id', 'label', 'name'])->keyBy('id')->all();
        $list = array_map(fn ($r) => $this->entryRow($r, $types), array_slice($rows, 0, self::LIST_MAX));
        $this->add('warning', 'books', count($rows) === 1 ? '1 entry is dated in the future' : count($rows).' entries are dated in the future',
            'These are dated after today, so they are left out of reports run up to today. Usually the year or month was typed wrong. If it is a real future date, you can ignore this.', $list, max(0, count($rows) - self::LIST_MAX));
    }

    // ---- customers and vendors ----------------------------------------------------------------------------------------------------

    /** Money sitting unapplied (payments or credit notes) for a customer or vendor who also has unpaid documents. */
    protected function checkUnapplied(string $side): void
    {
        if (! Tables::has('payment_allocations')) {
            return;
        }
        $ar = $side === 'ar';
        [$docT, $payT, $pcol, $live] = $ar ? ['invoices', 'payments', 'customer_id', 'received'] : ['bills', 'vendor_payments', 'vendor_id', 'paid'];
        $hasRefunds = Tables::has('credit_refunds');
        $allocatable = (! $ar && Tables::has('vendor_payments', 'posted_ledger_id')) ? ' AND (p.posted_ledger_id IS NULL OR p.posted_ledger_id = v.ledger_id)' : '';
        $joinV = $ar ? '' : " JOIN {$this->p('vendors')} v ON v.id = p.vendor_id";
        $refunds = fn ($type, $alias) => $hasRefunds ? "LEFT JOIN (SELECT src_id, SUM(amount) AS refunded FROM {$this->p('credit_refunds')} WHERE side = ? AND src_type = '$type' GROUP BY src_id) $alias ON $alias.src_id = %s" : "LEFT JOIN (SELECT NULL AS src_id, 0 AS refunded) $alias ON 1 = 0";
        $pa = $this->p('payment_allocations');
        $money = [];
        $q = "SELECT p.$pcol AS party, SUM(p.amount - COALESCE(a.applied, 0) - COALESCE(r.refunded, 0)) AS left_over FROM {$this->p($payT)} p$joinV
            LEFT JOIN (SELECT payment_id, SUM(amount) AS applied FROM $pa WHERE side = ? AND pay_type = 'pay' GROUP BY payment_id) a ON a.payment_id = p.id ".sprintf($refunds('pay', 'r'), 'p.id')." WHERE p.status = ?$allocatable GROUP BY p.$pcol";
        foreach ($this->db()->select($q, $hasRefunds ? [$side, $side, $live] : [$side, $live]) as $r) {
            $money[(int) $r->party] = $r->left_over;
        }
        if (Tables::has('credit_notes')) {
            $q = "SELECT n.party_id AS party, SUM(n.total - COALESCE(a.applied, 0) - COALESCE(r.refunded, 0)) AS left_over FROM {$this->p('credit_notes')} n
                LEFT JOIN (SELECT payment_id, SUM(amount) AS applied FROM $pa WHERE side = ? AND pay_type = 'credit' GROUP BY payment_id) a ON a.payment_id = n.id ".sprintf($refunds('credit', 'r'), 'n.id')." WHERE n.side = ? AND n.status = 'issued'".(Tables::has('credit_notes', 'kind') ? " AND n.kind <> 'writeoff'" : '').' GROUP BY n.party_id';
            foreach ($this->db()->select($q, $hasRefunds ? [$side, $side, $side] : [$side, $side]) as $r) {
                $money[(int) $r->party] = Money::add($money[(int) $r->party] ?? 0, $r->left_over);
            }
        }
        $owed = [];
        foreach ($this->db()->select("SELECT d.$pcol AS party, SUM(d.total - COALESCE(a.paid, 0)) AS due, SUM(CASE WHEN d.total - COALESCE(a.paid, 0) > 0 THEN 1 ELSE 0 END) AS docs FROM {$this->p($docT)} d
            LEFT JOIN (SELECT doc_id, SUM(amount) AS paid FROM $pa WHERE side = ? GROUP BY doc_id) a ON a.doc_id = d.id WHERE d.status = 'issued' GROUP BY d.$pcol", [$side]) as $r) {
            $owed[(int) $r->party] = $r;
        }
        $names = $this->db()->table($ar ? 'customers' : 'vendors')->pluck('name', 'id')->all();
        $list = [];
        foreach ($money as $party => $left) {
            if (! Money::positive($left) || ! isset($owed[$party]) || (int) $owed[$party]->docs < 1 || ! Money::positive($owed[$party]->due)) {
                continue;
            }
            $docs = (int) $owed[$party]->docs;
            $list[] = [$names[$party] ?? "#$party", $left, route($ar ? 'customers.show' : 'vendors.show', $party), 'unapplied, while '.$docs.' '.($ar ? 'invoice' : 'bill').($docs === 1 ? '' : 's').' ('.$this->money($owed[$party]->due).') show'.($docs === 1 ? 's' : '').' unpaid'];
        }
        if (! $list) {
            return;
        }
        usort($list, fn ($a, $b) => (float) $b[1] <=> (float) $a[1]);
        $n = count($list);
        $title = $ar ? ($n === 1 ? '1 customer has money not applied to their invoices' : "$n customers have money not applied to their invoices") : ($n === 1 ? '1 vendor has money not applied to their bills' : "$n vendors have money not applied to their bills");
        $why = $ar ? 'A payment or credit note was recorded but not matched to the invoices it pays, so those invoices still show as unpaid and appear in A/R Aging. The totals in the books are right - only the matching is missing. Open the customer, then the payment or credit note, and apply it to the invoices.'
            : 'A payment or supplier credit was recorded but not matched to the bills it pays, so those bills still show as unpaid and appear in A/P Aging. Open the vendor, then the payment or credit, and apply it to the bills.';
        $this->add('warning', 'parties', $title, $why, array_slice($list, 0, self::LIST_MAX), max(0, $n - self::LIST_MAX));
    }

    protected function checkOverdue(string $side, string $today): void
    {
        if (! Tables::has('payment_allocations')) {
            return;
        }
        $ar = $side === 'ar';
        $r = $this->db()->selectOne("SELECT COUNT(*) AS n, COALESCE(SUM(x.due), 0) AS due FROM (SELECT d.id, d.total - COALESCE(SUM(a.amount), 0) AS due FROM {$this->p($ar ? 'invoices' : 'bills')} d
            LEFT JOIN {$this->p('payment_allocations')} a ON a.doc_id = d.id AND a.side = ? WHERE d.status = 'issued' AND d.due_date IS NOT NULL AND d.due_date < ? GROUP BY d.id, d.total HAVING due > 0) x", [$side, $today]);
        if (! $r || (int) $r->n < 1) {
            return;
        }
        $n = (int) $r->n;
        $ar ? $this->add('info', 'parties', "$n overdue invoice".($n === 1 ? '' : 's').' - '.$this->money($r->due).' to collect',
            'Not an error - just money customers should already have paid. The A/R Aging report shows who and how late; Send reminder on the Customers page emails them their statement.', [['A/R Aging', $r->due, route('reports.ar-aging'), null]])
            : $this->add('info', 'parties', "$n overdue bill".($n === 1 ? '' : 's').' - '.$this->money($r->due).' to pay', 'Not an error - bills past their due date. The A/P Aging report shows which vendors and how late.', [['A/P Aging', $r->due, route('reports.ap-aging'), null]]);
    }

    // ---- documents and stock --------------------------------------------------------------------------------------------------------

    protected function checkOldDrafts(string $today): void
    {
        $cut = date('Y-m-d', strtotime($today.' -'.self::DRAFT_DAYS.' days'));
        $list = [];
        $count = 0;
        foreach ([['invoices', 'customer_id', 'customers', 'INV-', 'invoices.show'], ['bills', 'vendor_id', 'vendors', 'BILL-', 'bills.show']] as [$t, $col, $pt, $prefix, $route]) {
            $rows = $this->db()->select("SELECT d.id, d.number, d.date, d.total, p.name FROM {$this->p($t)} d LEFT JOIN {$this->p($pt)} p ON p.id = d.$col WHERE d.status = 'draft' AND d.date < ? ORDER BY d.date ASC, d.id ASC", [$cut]);
            $count += count($rows);
            foreach ($rows as $r) {
                if (count($list) < self::LIST_MAX) {
                    $list[] = [$prefix.str_pad((string) $r->number, 4, '0', STR_PAD_LEFT).' - '.$r->name, $r->total, route($route, $r->id), 'draft since '.Money::date($r->date)];
                }
            }
        }
        if (Tables::has('credit_notes')) {
            $rows = $this->db()->table('credit_notes')->where('status', 'draft')->where('date', '<', $cut)->orderBy('date')->orderBy('id')->get(['id', 'side', 'number', 'date', 'total']);
            $count += $rows->count();
            foreach ($rows as $r) {
                if (count($list) < self::LIST_MAX) {
                    $ar = $r->side === 'ar';
                    $list[] = [($ar ? 'CN-' : 'VC-').str_pad((string) $r->number, 4, '0', STR_PAD_LEFT), $r->total, route($ar ? 'credit-notes.show' : 'supplier-credits.show', $r->id), 'draft since '.Money::date($r->date)];
                }
            }
        }
        if (! $count) {
            return;
        }
        $this->add('info', 'documents', $count.' draft'.($count === 1 ? ' is' : 's are').' older than '.self::DRAFT_DAYS.' days',
            "A draft is not in the books - it doesn't count as a sale, a bill or stock movement until it is issued. Issue the ones that are real, and delete the ones that aren't needed.", $list, max(0, $count - self::LIST_MAX));
    }

    protected function checkNegativeStock(): void
    {
        $rows = $this->db()->table('items')->where('type', 'inventory')->where('quantity_on_hand', '<', 0)->orderBy('quantity_on_hand')->get(['id', 'name', 'quantity_on_hand']);
        if ($rows->isEmpty()) {
            return;
        }
        $list = $rows->take(self::LIST_MAX)->map(fn ($r) => [$r->name, null, route('items.index'), rtrim(rtrim((string) $r->quantity_on_hand, '0'), '.').' on hand'])->all();
        $n = $rows->count();
        $this->add('problem', 'stock', $n === 1 ? '1 item has less than zero in stock' : "$n items have less than zero in stock",
            "More was sold than was ever bought or counted in. If the supplier's bill was never entered, enter it with this item on it. If it was already paid another way (a quick expense, or a bill line without the item), don't enter it again - count the shelf and do a Stock Adjustment to the real quantity instead. Either one also clears a red Inventory account.",
            $list, max(0, $n - self::LIST_MAX));
    }

    protected function checkBackup(string $today): void
    {
        $last = app(BackupService::class)->last();
        if ($last && ! empty($last['at']) && substr($last['at'], 0, 10) >= date('Y-m-d', strtotime($today.' -'.self::BACKUP_DAYS.' days'))) {
            return;
        }
        $this->add('warning', 'safety', $last ? 'No backup downloaded in the last '.self::BACKUP_DAYS.' days' : 'No backup has been downloaded yet',
            $last ? 'The last backup was downloaded on '.Money::date(substr($last['at'], 0, 10)).'. Download a fresh copy and keep it somewhere safe off this computer.' : "A backup is a full copy of this company's data you keep yourself - if anything goes wrong, it can be put back. It takes a few seconds.",
            [['Settings - Backup', null, route('settings.backup'), null]]);
    }
}
