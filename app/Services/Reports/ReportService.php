<?php

namespace App\Services\Reports;

use App\Models\Tenant\EntryType;
use App\Models\Tenant\Group;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\PurchaseSetting;
use App\Models\Tenant\SalesSetting;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Services\Accounting\LedgerBalances;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Support\Facades\DB;

/**
 * The financial statements, ported from the legacy Reports controller and Qb_report library.
 *
 * Everything starts from a "tree": the groups and accounts under one top group (or the whole chart), each with
 * a net balance (debit positive) for a period. A tree built with no start date counts from the very beginning,
 * opening balances included; with a start date it holds only that period's movement.
 *
 * Statements show plain amounts on the side an account normally sits on (assets and expenses debit; liabilities,
 * equity and income credit), so a normal balance reads as a positive number, as in QuickBooks.
 */
class ReportService
{
    public function __construct(protected LedgerBalances $balances, protected ChartOfAccounts $chart, protected EntryService $entries) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    // ---- dates ----------------------------------------------------------------------------

    /** A Y-m-d date from the request, or $default. */
    public function readDate(?string $value, string $default): string
    {
        $value = trim((string) $value);

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : $default;
    }

    public function longDate(string $ymd): string
    {
        $t = strtotime($ymd.' 00:00:00');

        return $t ? date('F j, Y', $t) : $ymd;
    }

    public function fyStart(): string
    {
        return $this->chart->fyStartFor(now()->toDateString()) ?? now()->format('Y-01-01');
    }

    /** Today, kept inside the company's financial year. */
    public function defaultAsOf(): string
    {
        $s = \App\Models\Tenant\Setting::first();
        $fs = substr((string) ($s->fy_start ?? ''), 0, 10);
        $fe = substr((string) ($s->fy_end ?? ''), 0, 10);
        $today = now()->toDateString();
        if ($fe && $fe !== '0000-00-00' && $today > $fe) {
            return $fe;
        }
        if ($fs && $fs !== '0000-00-00' && $today < $fs) {
            return $fe ?: $today;
        }

        return $today;
    }

    public function dayBefore(string $ymd): string
    {
        return date('Y-m-d', strtotime($ymd.' 00:00:00 -1 day'));
    }

    // ---- parties --------------------------------------------------------------------------

    /** Customers' and vendors' own accounts: ledger id => 'ar' | 'ap'. */
    public function partyLedgers(): array
    {
        $out = [];
        foreach (['customers' => 'ar', 'vendors' => 'ap'] as $table => $side) {
            if (Tables::has($table)) {
                foreach ($this->db()->table($table)->whereNotNull('ledger_id')->pluck('ledger_id') as $id) {
                    $out[(int) $id] = $side;
                }
            }
        }

        return $out;
    }

    // ---- trees ----------------------------------------------------------------------------

    /**
     * The tree under a top group ($rootId null = every top group). A node is
     * ['id', 'name', 'groups' => [nodes], 'ledgers' => [['id','name','net','contra','party','parent_id']], 'net', 'any'];
     * `net` is debit positive and includes everything below; `any` says whether any account below has a balance.
     */
    public function tree(?int $rootId, ?string $start, ?string $end): array
    {
        $groups = Group::orderBy('code')->orderBy('id')->get(['id', 'parent_id', 'name', 'code']);
        $kids = $groups->groupBy(fn ($g) => (int) $g->parent_id);
        // all columns: older company databases have no parent_id (sub-accounts) or is_contra
        $ledgers = Ledger::orderBy('code')->orderBy('id')->get()->groupBy('group_id');
        $party = $this->partyLedgers();

        // every account under the root, so all balances come from two queries
        $ids = [];
        $collect = function (int $gid) use (&$collect, $kids, $ledgers, &$ids) {
            foreach ($ledgers->get($gid, []) as $l) {
                $ids[] = (int) $l->id;
            }
            foreach ($kids->get($gid, []) as $g) {
                $collect((int) $g->id);
            }
        };
        foreach ($rootId === null ? $kids->get(0, collect()) : [$groups->firstWhere('id', $rootId)] as $g) {
            if ($g) {
                $collect((int) $g->id);
            }
        }
        $bal = $this->balances->closingBalances($ids, $end, $start);

        $build = function ($g) use (&$build, $kids, $ledgers, $bal, $party) {
            $node = ['id' => (int) $g->id, 'name' => $g->name, 'groups' => [], 'ledgers' => [], 'net' => '0.00', 'any' => false];
            foreach ($ledgers->get($g->id, []) as $l) {
                $b = $bal[(int) $l->id] ?? ['dc' => 'D', 'amount' => '0.00'];
                $net = $b['dc'] === 'D' ? Money::add($b['amount'], 0) : Money::neg($b['amount']);
                $node['ledgers'][] = ['id' => (int) $l->id, 'name' => $l->name, 'net' => $net, 'contra' => (bool) $l->is_contra, 'party' => isset($party[(int) $l->id]), 'parent_id' => (int) ($l->parent_id ?? 0)];
                $node['net'] = Money::add($node['net'], $net);
                $node['any'] = $node['any'] || ! Money::isZero($net);
            }
            foreach ($kids->get($g->id, []) as $c) {
                $child = $build($c);
                $node['groups'][] = $child;
                $node['net'] = Money::add($node['net'], $child['net']);
                $node['any'] = $node['any'] || $child['any'];
            }

            return $node;
        };

        if ($rootId === null) {
            $root = ['id' => null, 'name' => 'All accounts', 'groups' => [], 'ledgers' => [], 'net' => '0.00', 'any' => false];
            foreach ($kids->get(0, []) as $g) {
                $child = $build($g);
                $root['groups'][] = $child;
                $root['net'] = Money::add($root['net'], $child['net']);
                $root['any'] = $root['any'] || $child['any'];
            }

            return $root;
        }
        $g = $groups->firstWhere('id', $rootId);

        return $g ? $build($g) : ['id' => null, 'name' => '', 'groups' => [], 'ledgers' => [], 'net' => '0.00', 'any' => false];
    }

    /** A tree for 'asset' | 'liability' | 'income' | 'expense'. */
    public function treeFor(string $kind, ?string $start, ?string $end): array
    {
        return $this->tree($this->chart->topLevelGroupId($kind), $start, $end);
    }

    /** A node's total on the side the statement reads it: 'D' as is, 'C' flipped. */
    public function side(string $net, string $normal): string
    {
        return $normal === 'D' ? $net : Money::neg($net);
    }

    /** Profit for a period (income - expenses; positive = profit). */
    public function netIncome(?string $start, ?string $end): string
    {
        return Money::sub($this->side($this->treeFor('income', $start, $end)['net'], 'C'), $this->side($this->treeFor('expense', $start, $end)['net'], 'D'));
    }

    // ---- rows -----------------------------------------------------------------------------

    protected ?array $groupLinks = null;

    protected function groupUrl(int $gid): ?string
    {
        $this->groupLinks ??= [
            (int) SalesSetting::find(1)?->ar_group_id => route('reports.customer-balance'),
            (int) PurchaseSetting::find(1)?->ap_group_id => route('reports.vendor-balance'),
        ];

        return $gid ? ($this->groupLinks[$gid] ?? null) : null;
    }

    protected function row(string $type, string $label, int $level, string $amount, ?string $url, $gid, array $path, bool $wrong): array
    {
        return compact('type', 'label', 'level', 'amount', 'url', 'gid', 'path', 'wrong');
    }

    /**
     * Rows for one section of a statement from a node: group headings, accounts, "Total ..." lines.
     * Accounts with nothing in them are left out. An account with sub-accounts reads as a heading, its own
     * amount as "Name - Other", the sub-accounts indented, then its total.
     */
    public function rows(array $node, string $normal, int $level = 0, array $path = []): array
    {
        $out = [];
        foreach ($node['groups'] as $g) {
            if ($g['any']) {
                array_push($out, ...$this->groupRows($g, $normal, $level, $path));
            }
        }
        $ids = array_flip(array_column($node['ledgers'], 'id'));
        $kids = [];
        foreach ($node['ledgers'] as $l) {
            if ($l['parent_id'] && isset($ids[$l['parent_id']])) {
                $kids[$l['parent_id']][] = $l;
            }
        }
        foreach ($node['ledgers'] as $l) {
            if ($l['parent_id'] && isset($ids[$l['parent_id']])) {
                continue; // drawn under its parent
            }
            array_push($out, ...$this->ledgerRows($l, $kids, $normal, $level, $path));
        }

        return $out;
    }

    protected function ledgerRows(array $l, array $kids, string $normal, int $level, array $path): array
    {
        $own = ($l['party'] || Money::isZero($l['net'])) ? '0.00' : $this->side($l['net'], $normal);
        $url = route('reports.statement', ['account' => $l['id']]);
        $inner = [];
        $innerTotal = '0.00';
        foreach ($kids[$l['id']] ?? [] as $k) {
            $kr = $this->ledgerRows($k, $kids, $normal, $level + 1, [...$path, 'L'.$l['id']]);
            if ($kr) {
                array_push($inner, ...$kr);
                $innerTotal = Money::add($innerTotal, end($kr)['amount']);
            }
        }
        if (! $inner) {
            return Money::isZero($own) ? [] : [$this->row('line', $l['name'], $level, $own, $url, null, $path, ! $l['contra'] && Money::cmp($own, 0) < 0)];
        }
        $total = Money::add($own, $innerTotal);
        $rows = [$this->row('group', $l['name'], $level, $total, null, 'L'.$l['id'], $path, false)];
        if (! Money::isZero($own)) {
            $rows[] = $this->row('line', $l['name'].' - Other', $level + 1, $own, $url, null, [...$path, 'L'.$l['id']], ! $l['contra'] && Money::cmp($own, 0) < 0);
        }
        array_push($rows, ...$inner);
        $rows[] = $this->row('total', 'Total '.$l['name'], $level, $total, null, 'L'.$l['id'], $path, ! $l['contra'] && Money::cmp($total, 0) < 0);

        return $rows;
    }

    protected function groupRows(array $g, string $normal, int $level, array $path): array
    {
        $amount = $this->side($g['net'], $normal);
        $inner = $this->rows($g, $normal, $level + 1, [...$path, $g['id']]);
        $wrong = Money::cmp($amount, 0) < 0 && ! $this->allContra($g);
        if (! $inner) {
            // only customer / vendor accounts here (Accounts Receivable / Payable): one line, like QuickBooks
            return [$this->row('line', $g['name'], $level, $amount, $this->groupUrl($g['id']), $g['id'], $path, $wrong)];
        }

        return [
            $this->row('group', $g['name'], $level, $amount, null, $g['id'], $path, $wrong),
            ...$inner,
            $this->row('total', 'Total '.$g['name'], $level, $amount, null, $g['id'], $path, $wrong),
        ];
    }

    /** Every account with a balance in this group (and below) is a contra account, like Accumulated Depreciation. */
    protected function allContra(array $g): bool
    {
        $any = false;
        foreach ($g['ledgers'] as $l) {
            if (Money::isZero($l['net'])) {
                continue;
            }
            if (! $l['contra']) {
                return false;
            }
            $any = true;
        }
        foreach ($g['groups'] as $c) {
            if (! $c['any']) {
                continue;
            }
            if (! $this->allContra($c)) {
                return false;
            }
            $any = true;
        }

        return $any;
    }

    // ---- Profit & Loss -------------------------------------------------------------------

    /** Income, Cost of Goods Sold, Gross Profit, Expense, Net Ordinary Income, Other Income / Expense, Net Income. */
    public function profitLoss(string $from, string $to): array
    {
        $inc = $this->treeFor('income', $from, $to);
        $exp = $this->treeFor('expense', $from, $to);

        $sec = ['income' => [], 'cogs' => [], 'expense' => [], 'other_income' => [], 'other_expense' => []];
        foreach ($inc['groups'] as $g) {
            $sec[preg_match('/^other\s+income/i', trim($g['name'])) ? 'other_income' : 'income'][] = $g;
        }
        foreach ($exp['groups'] as $g) {
            $n = trim($g['name']);
            $sec[preg_match('/cost\s+of\s+(goods|sales|revenue)|^purchases/i', $n) ? 'cogs' : (preg_match('/^other\s+expense/i', $n) ? 'other_expense' : 'expense')][] = $g;
        }

        [$incomeRows, $incomeTotal] = $this->sectionRows($sec['income'], 'C', $inc);
        [$cogsRows, $cogsTotal] = $this->sectionRows($sec['cogs'], 'D');
        [$expenseRows, $expenseTotal] = $this->sectionRows($sec['expense'], 'D', $exp);
        [$oiRows, $oiTotal] = $this->sectionRows($sec['other_income'], 'C');
        [$oeRows, $oeTotal] = $this->sectionRows($sec['other_expense'], 'D');

        $gross = Money::sub($incomeTotal, $cogsTotal);
        $netOrdinary = Money::sub($gross, $expenseTotal);
        $netOther = Money::sub($oiTotal, $oeTotal);

        return [
            'from' => $from, 'to' => $to,
            'income_rows' => $incomeRows, 'income_total' => $incomeTotal,
            'cogs_rows' => $cogsRows, 'cogs_total' => $cogsTotal, 'has_cogs' => (bool) $cogsRows || ! Money::isZero($cogsTotal),
            'gross' => $gross,
            'expense_rows' => $expenseRows, 'expense_total' => $expenseTotal,
            'net_ordinary' => $netOrdinary,
            'oi_rows' => $oiRows, 'oi_total' => $oiTotal, 'oe_rows' => $oeRows, 'oe_total' => $oeTotal,
            'has_other' => (bool) $oiRows || (bool) $oeRows, 'net_other' => $netOther,
            'net_income' => Money::add($netOrdinary, $netOther),
            // the two trees' own totals, as a cross-check on the sections
            'check' => Money::sub($this->side($inc['net'], 'C'), $this->side($exp['net'], 'D')),
        ];
    }

    /** Rows for a list of group nodes (one P&L section) and their total; $loose adds accounts sitting straight under the top group. */
    protected function sectionRows(array $nodes, string $normal, ?array $loose = null): array
    {
        $total = '0.00';
        $shown = [];
        foreach ($nodes as $g) {
            $total = Money::add($total, $this->side($g['net'], $normal));
            if ($g['any']) {
                $shown[] = $g;
            }
        }
        $looseLedgers = $loose['ledgers'] ?? [];
        $hasLoose = (bool) array_filter($looseLedgers, fn ($l) => ! Money::isZero($l['net']));

        if (count($shown) === 1 && ! $hasLoose) {
            // one group only: list its accounts straight under the heading instead of repeating the heading's name
            $rows = $this->rows($shown[0], $normal, 1) ?: $this->groupRows($shown[0], $normal, 1, []);
        } else {
            $rows = [];
            foreach ($shown as $g) {
                array_push($rows, ...$this->groupRows($g, $normal, 1, []));
            }
        }
        if ($looseLedgers) {
            array_push($rows, ...$this->rows(['groups' => [], 'ledgers' => $looseLedgers], $normal, 1));
            foreach ($looseLedgers as $l) {
                if (! $l['party']) {
                    $total = Money::add($total, $this->side($l['net'], $normal));
                }
            }
        }

        return [$rows, $total];
    }

    // ---- Balance Sheet --------------------------------------------------------------------

    /** Assets, then Liabilities & Equity with Retained Earnings and this year's Net Income inside Equity. */
    public function balanceSheet(string $asof): array
    {
        $assets = $this->treeFor('asset', null, $asof);
        $liab = $this->treeFor('liability', null, $asof);
        $netIncome = Money::sub($this->side($this->treeFor('income', null, $asof)['net'], 'C'), $this->side($this->treeFor('expense', null, $asof)['net'], 'D'));

        $assetRows = $this->rows($assets, 'D', 1);
        $assetsTotal = $this->side($assets['net'], 'D');
        $liabRows = $this->rows($liab, 'C', 1);
        $liabTotal = $this->side($liab['net'], 'C');

        $equityGid = null;
        foreach ($liab['groups'] as $g) {
            if (preg_match('/equity|capital/i', $g['name'])) {
                $equityGid = $g['id'];
            }
        }
        // profit of earlier financial years is Retained Earnings; only this year's is Net Income
        $fyS = $this->chart->fyStartFor($asof);
        $cur = $fyS ? $this->netIncome($fyS, $asof) : $netIncome;
        $prior = Money::sub($netIncome, $cur);
        $lines = [];
        if (! Money::isZero($prior)) {
            $lines[] = $this->row('line', 'Retained Earnings (profit of earlier years)', 2, $prior, route('reports.profit-loss', ['to' => $this->dayBefore($fyS)]), null, [$equityGid], false);
        }
        if (! Money::isZero($cur)) {
            $lines[] = $this->row('line', 'Net Income', 2, $cur, route('reports.profit-loss', ['from' => $fyS ?: $this->fyStart(), 'to' => $asof]), null, [$equityGid], false);
        }

        if ($lines) {
            $placed = false;
            if ($equityGid !== null) {
                foreach ($liabRows as $i => $r) {
                    if ($r['gid'] === $equityGid && $r['type'] === 'total') {
                        array_splice($liabRows, $i, 0, $lines);
                        $placed = true;
                        break;
                    }
                }
                if ($placed) {
                    foreach ($liabRows as $i => $r) {
                        if ($r['gid'] === $equityGid) {
                            $liabRows[$i]['amount'] = Money::add($r['amount'], $netIncome);
                            $liabRows[$i]['wrong'] = Money::cmp($liabRows[$i]['amount'], 0) < 0;
                        }
                    }
                } else {
                    // Equity has a balance but no accounts listed under it (a single line): make it a heading
                    foreach ($liabRows as $i => $r) {
                        if ($r['gid'] === $equityGid && $r['type'] === 'line') {
                            $amt = Money::add($r['amount'], $netIncome);
                            $repl = [array_merge($r, ['type' => 'group', 'amount' => $amt, 'url' => null, 'wrong' => Money::cmp($amt, 0) < 0])];
                            if (! Money::isZero($r['amount'])) {
                                $repl[] = array_merge($r, ['label' => $r['label'].' (accounts)', 'level' => $r['level'] + 1, 'gid' => null, 'path' => [...$r['path'], $equityGid]]);
                            }
                            foreach ($lines as $ln) {
                                $repl[] = array_merge($ln, ['level' => $r['level'] + 1, 'path' => [...$r['path'], $equityGid]]);
                            }
                            $repl[] = array_merge($r, ['type' => 'total', 'label' => 'Total '.$r['label'], 'amount' => $amt, 'url' => null, 'wrong' => Money::cmp($amt, 0) < 0]);
                            array_splice($liabRows, $i, 1, $repl);
                            $placed = true;
                            break;
                        }
                    }
                }
            }
            if (! $placed) {
                foreach ($lines as $ln) {
                    $liabRows[] = array_merge($ln, ['level' => 1, 'path' => []]);
                }
            }
        }
        $liabEqTotal = Money::add($liabTotal, $netIncome);
        $diff = $this->chart->openingDifference();

        return [
            'asof' => $asof, 'asset_rows' => $assetRows, 'assets_total' => $assetsTotal, 'liab_rows' => $liabRows, 'liab_eq_total' => $liabEqTotal,
            'net_income' => $netIncome, 'balanced' => Money::cmp($assetsTotal, $liabEqTotal) === 0, 'difference' => Money::sub($assetsTotal, $liabEqTotal),
            'opdiff' => Money::isZero($diff['amount']) ? null : $diff,
        ];
    }

    // ---- Trial Balance --------------------------------------------------------------------

    /** One line per account with a balance, in the Debit or Credit column; customers and vendors roll up into their group's line. */
    protected function tbRows(array $node, array &$rows): void
    {
        $rolled = '0.00';
        foreach ($node['ledgers'] as $l) {
            if ($l['party']) {
                $rolled = Money::add($rolled, $l['net']);

                continue;
            }
            if (Money::isZero($l['net'])) {
                continue;
            }
            $neg = Money::cmp($l['net'], 0) < 0;
            $rows[] = ['label' => $l['name'], 'debit' => $neg ? null : $l['net'], 'credit' => $neg ? Money::neg($l['net']) : null, 'url' => route('reports.statement', ['account' => $l['id']])];
        }
        if (! Money::isZero($rolled) && $node['id']) {
            $neg = Money::cmp($rolled, 0) < 0;
            $rows[] = ['label' => $node['name'], 'debit' => $neg ? null : $rolled, 'credit' => $neg ? Money::neg($rolled) : null, 'url' => $this->groupUrl($node['id'])];
        }
        foreach ($node['groups'] as $g) {
            $this->tbRows($g, $rows);
        }
    }

    public function trialBalance(string $asof): array
    {
        $rows = [];
        // income and expense show this financial year only; earlier years' profit is one Retained Earnings line
        if ($fyS = $this->chart->fyStartFor($asof)) {
            $this->tbRows($this->treeFor('asset', null, $asof), $rows);
            $this->tbRows($this->treeFor('liability', null, $asof), $rows);
            $prior = $this->netIncome(null, $this->dayBefore($fyS));
            if (! Money::isZero($prior)) {
                $neg = Money::cmp($prior, 0) < 0;
                $rows[] = ['label' => 'Retained Earnings (profit of earlier years)', 'debit' => $neg ? Money::neg($prior) : null, 'credit' => $neg ? null : $prior, 'url' => route('reports.profit-loss', ['to' => $this->dayBefore($fyS)])];
            }
            $this->tbRows($this->treeFor('income', $fyS, $asof), $rows);
            $this->tbRows($this->treeFor('expense', $fyS, $asof), $rows);
        } else {
            $this->tbRows($this->tree(null, null, $asof), $rows);
        }
        $dr = $cr = '0.00';
        foreach ($rows as $r) {
            $dr = Money::add($dr, $r['debit'] ?? 0);
            $cr = Money::add($cr, $r['credit'] ?? 0);
        }

        return ['asof' => $asof, 'rows' => $rows, 'debit' => $dr, 'credit' => $cr, 'balanced' => Money::cmp($dr, $cr) === 0];
    }

    // ---- Account statement ----------------------------------------------------------------

    /** How an account's two sides read, and which way its balance normally runs. */
    public function nature(Ledger $l): array
    {
        $party = $this->partyLedgers();
        $type = $this->chart->typeOfGroup((int) $l->group_id) ?? 'Asset';
        $normal = ChartOfAccounts::normalSide($type);
        if ($l->is_contra) {
            $normal = $normal === 'D' ? 'C' : 'D';
        }
        $class = match ($type) {'Asset' => 'asset', 'Expense' => 'expense', 'Income' => 'income', default => 'liability'};

        if (($party[$l->id] ?? null) === 'ar') {
            return ['normal' => 'D', 'in' => 'Charge', 'out' => 'Payment', 'kind' => 'customer', 'class' => $class];
        }
        if (($party[$l->id] ?? null) === 'ap') {
            return ['normal' => 'C', 'in' => 'Bill', 'out' => 'Payment', 'kind' => 'vendor', 'class' => $class];
        }
        if ((int) $l->type === 1) {
            return ['normal' => 'D', 'in' => 'Deposit', 'out' => 'Payment', 'kind' => 'bank', 'class' => $class];
        }

        return ['normal' => $normal, 'in' => 'Increase', 'out' => 'Decrease', 'kind' => 'other', 'class' => $class];
    }

    /** The document behind each entry (invoice, bill, payment ...): entry id => ['type','num','name','url']. Hand-made entries are left out. */
    public function entryDocs(array $entryIds): array
    {
        $out = [];
        $put = function ($eid, $type, $num, $name, $url) use (&$out) {
            $out[(int) $eid] ??= ['type' => $type, 'num' => (string) $num, 'name' => (string) $name, 'url' => $url];
        };
        $sales = app(\App\Services\Sales\SalesService::class);
        $purchases = app(\App\Services\Purchases\PurchasesService::class);

        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $entryIds)))), 500) as $ids) {
            if (Tables::has('invoices')) {
                foreach ($this->db()->table('invoices')->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')->whereIn('invoices.entry_id', $ids)->get(['invoices.id', 'invoices.entry_id', 'invoices.number', 'customers.name']) as $r) {
                    $put($r->entry_id, 'Invoice', $sales->label('invoice', $r->number), $r->name, route('invoices.show', $r->id));
                }
            }
            if (Tables::has('payments')) {
                foreach ($this->db()->table('payments')->leftJoin('customers', 'customers.id', '=', 'payments.customer_id')->whereIn('payments.entry_id', $ids)->get(['payments.id', 'payments.entry_id', 'payments.number', 'customers.name']) as $r) {
                    $put($r->entry_id, 'Payment', (int) $r->number, $r->name, route('payments.show', $r->id));
                }
            }
            if (Tables::has('bills')) {
                foreach ($this->db()->table('bills')->leftJoin('vendors', 'vendors.id', '=', 'bills.vendor_id')->whereIn('bills.entry_id', $ids)->get(['bills.id', 'bills.entry_id', 'bills.number', 'bills.vendor_ref', 'vendors.name']) as $r) {
                    $put($r->entry_id, 'Bill', ($r->vendor_ref !== null && $r->vendor_ref !== '') ? $r->vendor_ref : $purchases->billLabel($r->number), $r->name, route('bills.show', $r->id));
                }
            }
            if (Tables::has('vendor_payments')) {
                foreach ($this->db()->table('vendor_payments')->leftJoin('vendors', 'vendors.id', '=', 'vendor_payments.vendor_id')->whereIn('vendor_payments.entry_id', $ids)->get(['vendor_payments.id', 'vendor_payments.entry_id', 'vendor_payments.number', 'vendors.name']) as $r) {
                    $put($r->entry_id, 'Bill Payment', (int) $r->number, $r->name, route('vendor-payments.show', $r->id));
                }
            }
            if (Tables::has('expenses')) {
                foreach ($this->db()->table('expenses')->whereIn('entry_id', $ids)->get(['id', 'entry_id', 'number']) as $r) {
                    $put($r->entry_id, 'Expense', $purchases->expenseLabel($r->number), '', route('expenses.show', $r->id));
                }
            }
            if (Tables::has('credit_notes')) {
                foreach ($this->db()->table('credit_notes')->whereIn('entry_id', $ids)->get(['id', 'entry_id', 'number', 'side', 'kind', 'party_id']) as $r) {
                    $ar = $r->side === 'ar';
                    $name = $this->db()->table($ar ? 'customers' : 'vendors')->where('id', $r->party_id)->value('name');
                    $put($r->entry_id, $r->kind === 'writeoff' ? 'Write-off' : ($ar ? 'Credit Memo' : 'Vendor Credit'), $r->number, $name, null);
                }
            }
            if (Tables::has('credit_refunds')) {
                foreach ($this->db()->table('credit_refunds')->whereIn('entry_id', $ids)->get(['entry_id', 'side', 'party_id']) as $r) {
                    $put($r->entry_id, 'Refund', '', $this->db()->table($r->side === 'ar' ? 'customers' : 'vendors')->where('id', $r->party_id)->value('name'), null);
                }
            }
            if (Tables::has('stock_adjustments')) {
                foreach ($this->db()->table('stock_adjustments')->whereIn('entry_id', $ids)->get(['id', 'entry_id', 'number']) as $r) {
                    $put($r->entry_id, 'Inventory Adjust', $r->number, '', null);
                }
            }
        }

        return $out;
    }

    /** The "Split" column: the other account in each entry, or -SPLIT- when there are several. Customer / vendor accounts read as their group. */
    public function entrySplits(array $entryIds, int $ledgerId): array
    {
        $party = $this->partyLedgers();
        $groupNames = Group::pluck('name', 'id');
        $names = [];
        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $entryIds)))), 500) as $ids) {
            $rows = $this->db()->table('entryitems')->leftJoin('ledgers', 'ledgers.id', '=', 'entryitems.ledger_id')
                ->whereIn('entryitems.entry_id', $ids)->where('entryitems.ledger_id', '!=', $ledgerId)
                ->get(['entryitems.entry_id', 'entryitems.ledger_id', 'ledgers.name', 'ledgers.group_id']);
            foreach ($rows as $r) {
                $label = isset($party[(int) $r->ledger_id]) ? ($groupNames[$r->group_id] ?? ($party[(int) $r->ledger_id] === 'ar' ? 'Accounts Receivable' : 'Accounts Payable')) : $r->name;
                $names[(int) $r->entry_id][$label] = true;
            }
        }

        return array_map(fn ($set) => count($set) === 1 ? (string) array_key_first($set) : '-SPLIT-', $names);
    }

    /** Statement rows for one account between two dates (either may be null), in date order, with a running balance. */
    public function statement(Ledger $ledger, ?string $from, ?string $to): array
    {
        $nature = $this->nature($ledger);
        $normal = $nature['normal'];
        $opening = Money::signed($this->balances->openingBalance((int) $ledger->id, $from), $normal);

        // an income or expense account starts every financial year at zero: its opening is only what came in since that year began
        $fyS = $from ? $this->chart->fyStartFor($from) : null;
        if ($fyS && in_array($nature['class'], ['income', 'expense'], true) && $nature['kind'] === 'other') {
            $opening = '0.00';
            if ($fyS < $from) {
                $r = $this->db()->table('entryitems')->join('entries', 'entries.id', '=', 'entryitems.entry_id')
                    ->where('entryitems.ledger_id', $ledger->id)->where('entries.date', '>=', $fyS)->where('entries.date', '<', $from)
                    ->selectRaw("SUM(CASE WHEN entryitems.dc = 'D' THEN entryitems.amount ELSE 0 END) AS dr, SUM(CASE WHEN entryitems.dc = 'C' THEN entryitems.amount ELSE 0 END) AS cr")->first();
                $net = Money::sub($r->dr ?? 0, $r->cr ?? 0);
                $opening = $normal === 'D' ? $net : Money::neg($net);
            }
            $nature['fy_start'] = $fyS;
        }

        $items = $this->db()->table('entryitems')->join('entries', 'entries.id', '=', 'entryitems.entry_id')->leftJoin('entrytypes', 'entrytypes.id', '=', 'entries.entrytype_id')
            ->where('entryitems.ledger_id', $ledger->id)
            ->when($from, fn ($q) => $q->where('entries.date', '>=', $from))->when($to, fn ($q) => $q->where('entries.date', '<=', $to))
            ->orderBy('entries.date')->orderBy('entries.id')->orderBy('entryitems.id')
            ->get(['entries.id as entry_id', 'entries.date', 'entries.number', 'entries.entrytype_id', 'entries.notes as entry_notes', 'entrytypes.name as type_name', 'entrytypes.label as type_label', 'entryitems.amount', 'entryitems.dc', 'entryitems.narration']);

        $ids = $items->pluck('entry_id')->all();
        $docs = $this->entryDocs($ids);
        $splits = $this->entrySplits($ids, (int) $ledger->id);
        $types = EntryType::get()->keyBy('id');

        $balance = $opening;
        $totIn = $totOut = '0.00';
        $rows = [];
        foreach ($items as $it) {
            $isIn = $it->dc === $normal;
            $balance = $isIn ? Money::add($balance, $it->amount) : Money::sub($balance, $it->amount);
            $isIn ? $totIn = Money::add($totIn, $it->amount) : $totOut = Money::add($totOut, $it->amount);
            $doc = $docs[(int) $it->entry_id] ?? null;
            $entryUrl = route('entries.show', [$it->type_label, $it->entry_id]);
            $type = $types->get($it->entrytype_id);
            $rows[] = [
                'date' => $it->date,
                'type' => $doc['type'] ?? (EntryService::NAMES[$it->type_label] ?? ($it->type_name ?: 'Journal')),
                'num' => $doc ? $doc['num'] : ($type ? $this->entries->entryNumber($it->number, $type) : (string) $it->number),
                'name' => $doc['name'] ?? '',
                'memo' => (string) (($it->narration !== null && $it->narration !== '') ? $it->narration : $it->entry_notes),
                'split' => $splits[(int) $it->entry_id] ?? '',
                'in' => $isIn ? Money::add($it->amount, 0) : null, 'out' => $isIn ? null : Money::add($it->amount, 0),
                'balance' => $balance, 'url' => ($doc['url'] ?? null) ?: $entryUrl,
            ];
        }

        return ['nature' => $nature, 'opening' => $opening, 'rows' => $rows, 'total_in' => $totIn, 'total_out' => $totOut, 'closing' => $balance];
    }

    // ---- Aging ----------------------------------------------------------------------------

    public const BUCKETS = ['current' => 'Current', '1_30' => '1 - 30', '31_60' => '31 - 60', '61_90' => '61 - 90', '90_plus' => 'Over 90'];

    public function bucketFor(?string $dueDate, string $asOf): string
    {
        if (empty($dueDate)) {
            return 'current';
        }
        $days = (int) floor((strtotime($asOf.' 00:00:00') - strtotime(substr($dueDate, 0, 10).' 00:00:00')) / 86400);

        return match (true) {$days <= 0 => 'current', $days <= 30 => '1_30', $days <= 60 => '31_60', $days <= 90 => '61_90', default => '90_plus'};
    }

    /**
     * Every open invoice's (bill's) own balance in the column for its due date, as it stood on $asOf: documents dated later
     * are left out and only payments and credit notes dated on or before $asOf count against a document. "Credits & other"
     * is what the documents don't explain, so each row still adds up to the ledger balance and the total is the real A/R (A/P).
     */
    public function aging(string $side, string $asOf): array
    {
        $isAr = $side === 'ar';
        $docTable = $isAr ? 'invoices' : 'bills';
        $partyCol = $isAr ? 'customer_id' : 'vendor_id';
        $parties = app(\App\Services\Parties\PartyService::class)->all($side, $asOf);

        $docs = $this->db()->table($docTable)->where('status', 'issued')->where('date', '<=', $asOf)->get(['id', $partyCol.' as party_id', 'due_date', 'total']);

        $paid = [];
        $sources = [['pay', $isAr ? 'payments' : 'vendor_payments']];
        if (Tables::has('credit_notes')) {
            $sources[] = ['credit', 'credit_notes'];
        }
        foreach ($sources as [$type, $table]) {
            foreach ($this->db()->table('payment_allocations as a')->join("$table as p", 'p.id', '=', 'a.payment_id')
                ->where('a.side', $side)->where('a.pay_type', $type)->where('p.date', '<=', $asOf)->groupBy('a.doc_id')->selectRaw('a.doc_id, SUM(a.amount) AS paid')->get() as $r) {
                $paid[(int) $r->doc_id] = Money::add($paid[(int) $r->doc_id] ?? 0, $r->paid);
            }
        }

        $zero = array_fill_keys(array_keys(self::BUCKETS), '0.00');
        $byParty = [];
        foreach ($docs as $d) {
            $due = Money::sub($d->total, $paid[(int) $d->id] ?? 0);
            if (! Money::positive($due)) {
                continue;
            }
            $b = $this->bucketFor($d->due_date, $asOf);
            $byParty[(int) $d->party_id] ??= $zero;
            $byParty[(int) $d->party_id][$b] = Money::add($byParty[(int) $d->party_id][$b], $due);
        }

        $normal = $isAr ? 'D' : 'C';
        $totals = $zero + ['other' => '0.00', 'total' => '0.00'];
        $rows = [];
        foreach ($parties as $p) {
            $buckets = $byParty[(int) $p->id] ?? $zero;
            $owed = Money::signed($p->balance, $normal); // positive = owed to us (A/R) or by us (A/P)
            $docSum = array_reduce($buckets, fn ($c, $a) => Money::add($c, $a), '0.00');
            if (Money::isZero($owed) && Money::isZero($docSum)) {
                continue;
            }
            $other = Money::sub($owed, $docSum);
            foreach ($buckets as $k => $a) {
                $totals[$k] = Money::add($totals[$k], $a);
            }
            $totals['other'] = Money::add($totals['other'], $other);
            $totals['total'] = Money::add($totals['total'], $owed);
            $rows[] = ['id' => (int) $p->id, 'name' => $p->name, 'buckets' => $buckets, 'other' => $other, 'total' => $owed];
        }

        return ['as_of' => $asOf, 'rows' => $rows, 'totals' => $totals];
    }

    // ---- Journal --------------------------------------------------------------------------

    /** Every entry between two dates as a block of lines; customer / vendor accounts read as Accounts Receivable / Payable. */
    public function journal(string $from, string $to): array
    {
        $party = $this->partyLedgers();
        $groupNames = Group::pluck('name', 'id');
        $lines = $this->db()->table('entryitems')->join('entries', 'entries.id', '=', 'entryitems.entry_id')->leftJoin('entrytypes', 'entrytypes.id', '=', 'entries.entrytype_id')
            ->leftJoin('ledgers', 'ledgers.id', '=', 'entryitems.ledger_id')
            ->whereBetween('entries.date', [$from, $to])->orderBy('entries.date')->orderBy('entries.id')->orderBy('entryitems.id')
            ->get(['entries.id as entry_id', 'entries.date', 'entries.number', 'entries.entrytype_id', 'entries.notes as entry_notes', 'entrytypes.name as type_name', 'entrytypes.label as type_label',
                'entryitems.ledger_id', 'entryitems.amount', 'entryitems.dc', 'entryitems.narration', 'ledgers.name as ledger_name', 'ledgers.group_id']);

        $docs = $this->entryDocs($lines->pluck('entry_id')->all());
        $types = EntryType::get()->keyBy('id');
        $entries = [];
        foreach ($lines as $l) {
            $eid = (int) $l->entry_id;
            if (! isset($entries[$eid])) {
                $doc = $docs[$eid] ?? null;
                $type = $types->get($l->entrytype_id);
                $entries[$eid] = ['date' => $l->date, 'type' => $doc['type'] ?? (EntryService::NAMES[$l->type_label] ?? ($l->type_name ?: 'Journal')),
                    'num' => $doc ? $doc['num'] : ($type ? $this->entries->entryNumber($l->number, $type) : (string) $l->number), 'name' => $doc['name'] ?? '',
                    'url' => ($doc['url'] ?? null) ?: route('entries.show', [$l->type_label, $eid]), 'lines' => []];
            }
            $acct = isset($party[(int) $l->ledger_id]) ? ($groupNames[$l->group_id] ?? ($party[(int) $l->ledger_id] === 'ar' ? 'Accounts Receivable' : 'Accounts Payable')) : $l->ledger_name;
            $entries[$eid]['lines'][] = ['account' => $acct, 'memo' => (string) (($l->narration !== null && $l->narration !== '') ? $l->narration : $l->entry_notes),
                'debit' => $l->dc === 'D' ? Money::add($l->amount, 0) : null, 'credit' => $l->dc === 'C' ? Money::add($l->amount, 0) : null];
        }

        return array_values($entries);
    }
}
