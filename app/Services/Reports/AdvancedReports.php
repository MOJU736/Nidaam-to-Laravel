<?php

namespace App\Services\Reports;

use App\Models\Tenant\Group;
use App\Models\Tenant\Ledger;
use App\Services\Accounting\LedgerBalances;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Support\Facades\DB;

/** The reports an accountant asks for: cash flow, general ledger, trial balance in detail, balance sheet comparison, profit by item and payments received. */
class AdvancedReports
{
    public function __construct(protected ReportService $reports, protected LedgerBalances $balances) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    // ---- statement of cash flows (indirect method) ---------------------------------------------------------------------------

    /**
     * Net income, adjusted by every change in a non-cash asset, liability or equity account, in Operating / Investing / Financing sections,
     * then cash at the beginning and end. Fixed assets are Investing; equity and long-term loans are Financing; everything else Operating.
     * Customers and vendors roll into one Accounts Receivable / Payable line each.
     */
    public function cashFlow(string $from, string $to): array
    {
        $before = $this->reports->dayBefore($from);
        $types = Ledger::pluck('type', 'id')->all();
        $cf = ['net_profit' => $this->reports->netIncome($from, $to), 'sections' => ['operating' => [], 'investing' => [], 'financing' => []], 'totals' => ['operating' => '0.00', 'investing' => '0.00', 'financing' => '0.00'],
            'net_change' => '0.00', 'cash_begin' => '0.00', 'cash_end_computed' => '0.00', 'cash_end_actual' => '0.00', 'ties_out' => true, 'has_cash_ledger' => false];
        $cashOp = '0.00';
        $cashCl = '0.00';
        $hasCash = false;
        $lines = [];
        foreach ([['asset', 'D'], ['liability', 'C']] as [$kind, $normal]) {
            $open = $this->reports->treeFor($kind, null, $before);
            $close = $this->reports->treeFor($kind, null, $to);
            $this->cashWalk($open, $close, $normal, $normal === 'D', $types, $cashOp, $cashCl, $hasCash, 'operating', $lines);
        }
        foreach ($lines as $l) {
            $cf['sections'][$l['section']][] = $l;
            $cf['totals'][$l['section']] = Money::add($cf['totals'][$l['section']], $l['cash_effect']);
        }
        $cf['totals']['operating'] = Money::add($cf['totals']['operating'], $cf['net_profit']);
        $cf['net_change'] = Money::add(Money::add($cf['totals']['operating'], $cf['totals']['investing']), $cf['totals']['financing']);
        $cf['has_cash_ledger'] = $hasCash;
        $cf['cash_begin'] = $cashOp;
        $cf['cash_end_actual'] = $cashCl;
        $cf['cash_end_computed'] = Money::add($cashOp, $cf['net_change']);
        $cf['ties_out'] = Money::cmp($cf['cash_end_computed'], $cashCl) === 0;

        return $cf;
    }

    protected function cashWalk(array $open, array $close, string $normal, bool $excludeCash, array $types, string &$cashOp, string &$cashCl, bool &$hasCash, string $section, array &$lines): void
    {
        $openLedgers = collect($open['ledgers'])->keyBy('id');
        $rolled = '0.00';
        // a bank or cash account: ticked as one, or sitting in a Cash / Bank group
        $cashGroup = (bool) preg_match('/\bcash\b|\bbank|checking|savings/i', (string) ($close['name'] ?? ''));
        foreach ($close['ledgers'] as $l) {
            $o = $openLedgers->get($l['id']);
            if ($excludeCash && ((int) ($types[$l['id']] ?? 0) === 1 || $cashGroup)) {
                $hasCash = true;
                $cashOp = Money::add($cashOp, $o['net'] ?? 0);
                $cashCl = Money::add($cashCl, $l['net']);

                continue;
            }
            $opSigned = $this->reports->side((string) ($o['net'] ?? '0.00'), $normal);
            $clSigned = $this->reports->side($l['net'], $normal);
            $change = Money::sub($clSigned, $opSigned);
            if (Money::isZero($change)) {
                continue;
            }
            $effect = $normal === 'D' ? Money::neg($change) : $change; // an asset going up ties up cash; a liability going up keeps it
            if ($l['party']) {
                $rolled = Money::add($rolled, $effect);

                continue;
            }
            $lines[] = ['label' => $l['name'], 'cash_effect' => $effect, 'section' => $section];
        }
        if (! Money::isZero($rolled)) {
            $lines[] = ['label' => $close['name'], 'cash_effect' => $rolled, 'section' => $section];
        }
        $openGroups = collect($open['groups'])->keyBy('id');
        foreach ($close['groups'] as $g) {
            $child = $section;
            if ($section === 'operating') {
                if ($normal === 'D' && preg_match('/fixed|property|equipment|non.?current|long.?term|depreciation/i', $g['name'])) {
                    $child = 'investing';
                } elseif ($normal === 'C' && preg_match('/equity|capital|long.?term|loans?\b/i', $g['name'])) {
                    $child = 'financing';
                }
            }
            $this->cashWalk($openGroups->get($g['id'], ['id' => $g['id'], 'name' => $g['name'], 'groups' => [], 'ledgers' => []]), $g, $normal, $excludeCash, $types, $cashOp, $cashCl, $hasCash, $child, $lines);
        }
    }

    // ---- general ledger ---------------------------------------------------------------------------------------------------------

    /** Every account that moved in the dates, in chart order, each with its opening balance, transactions and running balance. */
    public function generalLedger(string $from, string $to): array
    {
        $active = $this->db()->table('entryitems')->join('entries', 'entries.id', '=', 'entryitems.entry_id')->whereBetween('entries.date', [$from, $to])->distinct()->pluck('entryitems.ledger_id')->flip();
        $groups = Group::orderBy('code')->orderBy('id')->get()->keyBy('id');
        $ledgers = Ledger::orderBy('code')->orderBy('name')->get()->filter(fn ($l) => $active->has($l->id));
        $path = function ($gid) use ($groups) {
            $parts = [];
            for ($i = 0; $gid && isset($groups[$gid]) && $i < 10; $i++, $gid = (int) $groups[$gid]->parent_id) {
                array_unshift($parts, $groups[$gid]->name);
            }

            return implode(' › ', $parts);
        };
        $order = $groups->keys()->flip();
        $out = [];
        foreach ($ledgers->sortBy(fn ($l) => sprintf('%08d', $order[$l->group_id] ?? 0).'|'.$l->code.'|'.$l->name) as $l) {
            $st = $this->reports->statement($l, $from, $to);
            $out[] = ['id' => (int) $l->id, 'name' => trim(($l->code ? $l->code.' ' : '').$l->name), 'group' => $path($l->group_id), 'st' => $st, 'net' => Money::sub($st['total_in'], $st['total_out'])];
        }

        return ['accounts' => $out, 'from' => $from, 'to' => $to];
    }

    // ---- trial balance in detail --------------------------------------------------------------------------------------------------

    /** Opening balance, all debits, all credits and closing balance for every group and account (the accountant's layout). */
    public function trialBalanceDetail(string $asof): array
    {
        $tree = $this->reports->tree(null, null, $asof);
        $ids = [];
        $collect = function (array $n) use (&$collect, &$ids) {
            foreach ($n['ledgers'] as $l) {
                $ids[] = $l['id'];
            }
            foreach ($n['groups'] as $g) {
                $collect($g);
            }
        };
        $collect($tree);
        $bal = $this->balances->closingBalances($ids, $asof);
        $ops = Ledger::whereIn('id', $ids)->get(['id', 'op_balance', 'op_balance_dc', 'code'])->keyBy('id');
        $zero = ['op' => '0.00', 'dr' => '0.00', 'cr' => '0.00', 'cl' => '0.00'];
        $walk = function (array $n, int $level) use (&$walk, $bal, $ops, $zero) {
            $rows = [];
            $tot = $zero;
            foreach ($n['ledgers'] as $l) {
                $b = $bal[$l['id']] ?? ['dc' => 'D', 'amount' => '0.00', 'dr_total' => '0.00', 'cr_total' => '0.00'];
                $o = $ops[$l['id']] ?? null;
                $op = $o && $o->op_balance ? ($o->op_balance_dc === 'D' ? Money::add($o->op_balance, 0) : Money::neg($o->op_balance)) : '0.00';
                $v = ['op' => $op, 'dr' => $b['dr_total'], 'cr' => $b['cr_total'], 'cl' => $b['dc'] === 'D' ? $b['amount'] : Money::neg($b['amount'])];
                foreach ($v as $k => $x) {
                    $tot[$k] = Money::add($tot[$k], $x);
                }
                if ($l['party'] || ! array_filter($v, fn ($x) => ! Money::isZero($x))) { // customers and vendors roll into their group; dormant accounts are skipped
                    continue;
                }
                $rows[] = ['type' => 'ledger', 'id' => $l['id'], 'label' => $l['name'], 'level' => $level, 'v' => $v];
            }
            foreach ($n['groups'] as $g) {
                [$r, $t] = $walk($g, $level + 1);
                foreach ($t as $k => $x) {
                    $tot[$k] = Money::add($tot[$k], $x);
                }
                if (! $r && ! $g['any']) {
                    continue;
                }
                $rows[] = ['type' => 'group', 'id' => $g['id'], 'label' => $g['name'], 'level' => $level, 'v' => $t];
                array_push($rows, ...$r);
            }

            return [$rows, $tot];
        };
        [$rows, $total] = $walk($tree, 0);

        // opening balances count on their own side; the report balances when total debits equal total credits
        $dr = $cr = '0.00';
        foreach ($ops as $o) {
            if ($o->op_balance) {
                $o->op_balance_dc === 'D' ? $dr = Money::add($dr, $o->op_balance) : $cr = Money::add($cr, $o->op_balance);
            }
        }
        $dr = Money::add($dr, $total['dr']);
        $cr = Money::add($cr, $total['cr']);

        return ['rows' => $rows, 'total' => $total, 'debits' => $dr, 'credits' => $cr, 'balanced' => Money::cmp($dr, $cr) === 0];
    }

    // ---- balance sheet comparison --------------------------------------------------------------------------------------------------

    /** The balance sheet on two dates, row by row: every row has a (this date) and b (the other date). */
    public function bsCompare(string $asof, string $other): array
    {
        $a = $this->reports->balanceSheet($asof);
        $b = $this->reports->balanceSheet($other);
        $merge = function (array $ra, array $rb) {
            $key = fn ($r) => $r['type'].'|'.($r['gid'] ?? $r['label']).'|'.$r['level'];
            $order = $va = $vb = $meta = [];
            foreach ($ra as $r) {
                $k = $key($r);
                $order[] = $k;
                $va[$k] = $r['amount'];
                $meta[$k] = $r;
            }
            $last = -1;
            foreach ($rb as $r) {
                $k = $key($r);
                $vb[$k] = $r['amount'];
                $pos = array_search($k, $order, true);
                if ($pos === false) {
                    array_splice($order, $last + 1, 0, [$k]);
                    $meta[$k] = $r;
                    $last++;
                } else {
                    $last = $pos;
                }
            }
            $out = [];
            foreach ($order as $k) {
                $out[] = $meta[$k] + ['a' => $va[$k] ?? '0.00', 'b' => $vb[$k] ?? '0.00'];
            }

            return $out;
        };

        return ['asof' => $asof, 'other' => $other, 'asset_rows' => $merge($a['asset_rows'], $b['asset_rows']), 'liab_rows' => $merge($a['liab_rows'], $b['liab_rows']),
            'assets' => [$a['assets_total'], $b['assets_total']], 'liab_eq' => [$a['liab_eq_total'], $b['liab_eq_total']]];
    }

    // ---- profit by item -------------------------------------------------------------------------------------------------------------

    /** Sold item lines in the dates: issued invoices (cost = the stock cost booked when issued) and customer credit notes (minus; cost back when restocked). */
    public function soldLines(string $from, string $to, int $customerId = 0, int $itemId = 0): array
    {
        $out = [];
        $hasCogs = Tables::has('invoice_items', 'cogs_amount');
        $q = $this->db()->table('invoice_items as ii')->join('invoices as i', 'i.id', '=', 'ii.invoice_id')->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')->leftJoin('items as it', 'it.id', '=', 'ii.item_id')
            ->where('i.status', 'issued')->whereBetween('i.date', [$from, $to])->when($customerId, fn ($x) => $x->where('i.customer_id', $customerId))->when($itemId, fn ($x) => $x->where('ii.item_id', $itemId))
            ->orderBy('i.date')->orderBy('i.id')->get(['ii.*', 'i.number as doc_number', 'i.date as doc_date', 'i.id as doc_id', 'i.customer_id', 'c.name as customer_name', 'it.name as item_name']);
        foreach ($q as $r) {
            $out[] = ['date' => $r->doc_date, 'customer_id' => (int) $r->customer_id, 'customer' => $r->customer_name ?: '(no name)', 'item_id' => (int) $r->item_id, 'item' => $r->item_name ?: $r->description,
                'qty' => (float) $r->qty, 'price' => $r->unit_price, 'amount' => Money::sub($r->amount, $r->discount_amount ?? 0), 'cost' => ($hasCogs && $r->cogs_amount !== null) ? (string) $r->cogs_amount : null, 'kind' => 'sale'];
        }
        if (Tables::has('credit_notes') && Tables::has('credit_note_items')) {
            $hasCost = Tables::has('credit_note_items', 'cost_amount');
            $q = $this->db()->table('credit_note_items as ci')->join('credit_notes as n', 'n.id', '=', 'ci.credit_note_id')->leftJoin('customers as c', 'c.id', '=', 'n.party_id')->leftJoin('items as it', 'it.id', '=', 'ci.item_id')
                ->where('n.side', 'ar')->where('n.status', 'issued')->where('n.kind', '!=', 'writeoff')->whereBetween('n.date', [$from, $to])
                ->when($customerId, fn ($x) => $x->where('n.party_id', $customerId))->when($itemId, fn ($x) => $x->where('ci.item_id', $itemId))
                ->get(['ci.*', 'n.date as doc_date', 'n.party_id as customer_id', 'c.name as customer_name', 'it.name as item_name']);
            foreach ($q as $r) {
                $out[] = ['date' => $r->doc_date, 'customer_id' => (int) $r->customer_id, 'customer' => $r->customer_name ?: '(no name)', 'item_id' => (int) $r->item_id, 'item' => $r->item_name ?: $r->description,
                    'qty' => -1 * (float) $r->qty, 'price' => $r->unit_price, 'amount' => Money::neg($r->amount), 'cost' => ($hasCost && $r->cost_amount !== null && ! empty($r->restock)) ? Money::neg($r->cost_amount) : null, 'kind' => 'return'];
            }
        }

        return $out;
    }

    /** Sums lines into one row: sold / returned qty, net amount, cost, profit, margin, last price and date. */
    public function sumSold(array $lines): array
    {
        $t = ['sold' => 0.0, 'returned' => 0.0, 'returned_amount' => '0.00', 'amount' => '0.00', 'cost' => '0.00', 'costed' => '0.00', 'no_cost' => false, 'times' => 0, 'last_date' => null, 'last_price' => null];
        foreach ($lines as $l) {
            if ($l['kind'] === 'sale') {
                $t['sold'] += $l['qty'];
                $t['times']++;
                if ($t['last_date'] === null || $l['date'] >= $t['last_date']) {
                    $t['last_date'] = $l['date'];
                    $t['last_price'] = $l['price'];
                }
            } else {
                $t['returned'] += -$l['qty'];
                $t['returned_amount'] = Money::add($t['returned_amount'], Money::neg($l['amount']));
            }
            $t['amount'] = Money::add($t['amount'], $l['amount']);
            if ($l['cost'] !== null) {
                $t['cost'] = Money::add($t['cost'], $l['cost']);
                $t['costed'] = Money::add($t['costed'], $l['amount']);
            } elseif ($l['kind'] === 'sale') {
                $t['no_cost'] = true;
            }
        }
        $t['profit'] = Money::sub($t['amount'], $t['cost']);
        $t['margin'] = abs((float) $t['amount']) > 0.004 ? (float) $t['profit'] / (float) $t['amount'] * 100 : null;

        return $t;
    }

    /** What each item sold for, what it cost and what was left. */
    public function profitByItem(string $from, string $to, string $sort = 'profit'): array
    {
        $by = [];
        $all = [];
        foreach ($this->soldLines($from, $to) as $l) {
            $k = $l['item_id'] ? 'i'.$l['item_id'] : 'd'.mb_strtolower($l['item']);
            $by[$k]['name'] = $l['item'];
            $by[$k]['id'] = $l['item_id'];
            $by[$k]['lines'][] = $l;
            $all[] = $l;
        }
        $rows = [];
        foreach ($by as $b) {
            $rows[] = ['name' => $b['name'], 'id' => $b['id']] + $this->sumSold($b['lines']);
        }
        usort($rows, fn ($a, $b) => $sort === 'name' ? strcasecmp($a['name'], $b['name']) : ((float) $b['profit'] <=> (float) $a['profit']));

        return ['rows' => $rows, 'total' => $this->sumSold($all), 'sort' => $sort];
    }

    // ---- payments received ------------------------------------------------------------------------------------------------------------

    /** Customer payments in the dates (voided left out), grouped by how they were paid or where the money went. */
    public function paymentsReceived(string $from, string $to, string $group = 'method'): array
    {
        $rows = $this->db()->table('payments as p')->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')->leftJoin('ledgers as l', 'l.id', '=', 'p.ledger_id')
            ->whereBetween('p.date', [$from, $to])->where('p.status', '!=', 'void')->orderBy('p.date')->orderBy('p.id')->get(['p.*', 'c.name as customer_name', 'l.name as account_name']);
        $groups = $byMethod = $byAccount = [];
        $total = '0.00';
        foreach ($rows as $r) {
            $m = trim((string) $r->method) !== '' ? ucwords(str_replace('_', ' ', trim((string) $r->method))) : 'Not set';
            $acc = $r->account_name ?: 'Unknown account';
            $r->method_label = $m;
            $r->account_label = $acc;
            $k = $group === 'account' ? $acc : $m;
            $groups[$k] ??= ['name' => $k, 'rows' => [], 'total' => '0.00'];
            $groups[$k]['rows'][] = $r;
            $groups[$k]['total'] = Money::add($groups[$k]['total'], $r->amount);
            $byMethod[$m] = Money::add($byMethod[$m] ?? 0, $r->amount);
            $byAccount[$acc] = Money::add($byAccount[$acc] ?? 0, $r->amount);
            $total = Money::add($total, $r->amount);
        }
        ksort($groups);
        arsort($byMethod);

        return ['groups' => $groups, 'by_method' => $byMethod, 'by_account' => $byAccount, 'total' => $total, 'count' => count($rows), 'group' => $group];
    }

    // ---- customer items statement ---------------------------------------------------------------------------------------------------

    /** Every item a customer (or all customers) took, line by line with the document number; credit notes are minus lines. */
    public function customerItems(int $customerId, int $itemId, string $from, string $to, bool $profit = false): array
    {
        $credits = app(\App\Services\Credits\CreditService::class);
        $groups = [];
        $add = function (int $cid, ?string $cname, array $line) use (&$groups) {
            $groups[$cid] ??= ['id' => $cid, 'name' => $cname ?: '(no name)', 'lines' => [], 'qty' => 0.0, 'amount' => '0.00', 'docs' => [], 'items' => []];
            $g = &$groups[$cid];
            $g['lines'][] = $line;
            $g['qty'] += $line['qty'];
            $g['amount'] = Money::add($g['amount'], $line['amount']);
            $g['docs'][$line['doc']] = 1;
            $k = $line['item_id'] ? 'i'.$line['item_id'] : 'd'.mb_strtolower($line['item']);
            $g['items'][$k] ??= ['item' => $line['item'], 'qty' => 0.0, 'amount' => '0.00', 'times' => 0];
            $g['items'][$k]['qty'] += $line['qty'];
            $g['items'][$k]['amount'] = Money::add($g['items'][$k]['amount'], $line['amount']);
            $g['items'][$k]['times']++;
        };
        $rows = $this->db()->table('invoice_items as ii')->join('invoices as i', 'i.id', '=', 'ii.invoice_id')->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')->leftJoin('items as it', 'it.id', '=', 'ii.item_id')
            ->where('i.status', 'issued')->whereBetween('i.date', [$from, $to])->when($customerId, fn ($x) => $x->where('i.customer_id', $customerId))->when($itemId, fn ($x) => $x->where('ii.item_id', $itemId))
            ->orderBy('i.date')->orderBy('i.number')->orderBy('ii.sort_order')->orderBy('ii.id')->get(['ii.*', 'i.number as doc_number', 'i.date as doc_date', 'i.id as doc_id', 'i.customer_id', 'c.name as customer_name', 'it.name as item_name']);
        foreach ($rows as $r) {
            $add((int) $r->customer_id, $r->customer_name, ['date' => $r->doc_date, 'doc' => 'INV-'.str_pad((string) $r->doc_number, 4, '0', STR_PAD_LEFT), 'url' => route('invoices.show', $r->doc_id), 'kind' => 'invoice',
                'item_id' => (int) $r->item_id, 'item' => $r->item_name ?: $r->description, 'description' => ($r->item_name && $r->description !== $r->item_name) ? $r->description : '', 'qty' => (float) $r->qty,
                'price' => $r->unit_price, 'discount' => (float) $r->discount_amount > 0 ? $r->discount_amount : null, 'amount' => Money::sub($r->amount, $r->discount_amount ?? 0)]);
        }
        if (Tables::has('credit_notes') && Tables::has('credit_note_items')) {
            $q = $this->db()->table('credit_note_items as ci')->join('credit_notes as n', 'n.id', '=', 'ci.credit_note_id')->leftJoin('customers as c', 'c.id', '=', 'n.party_id')->leftJoin('items as it', 'it.id', '=', 'ci.item_id')
                ->where('n.side', 'ar')->where('n.status', 'issued')->where('n.kind', '!=', 'writeoff')->whereBetween('n.date', [$from, $to])
                ->when($customerId, fn ($x) => $x->where('n.party_id', $customerId))->when($itemId, fn ($x) => $x->where('ci.item_id', $itemId))
                ->get(['ci.*', 'n.number as doc_number', 'n.date as doc_date', 'n.id as doc_id', 'n.kind as doc_kind', 'n.party_id as customer_id', 'c.name as customer_name', 'it.name as item_name']);
            foreach ($q as $r) {
                $add((int) $r->customer_id, $r->customer_name, ['date' => $r->doc_date, 'doc' => $credits->label('ar', $r->doc_number, $r->doc_kind), 'url' => route('credit-notes.show', $r->doc_id), 'kind' => 'credit',
                    'item_id' => (int) $r->item_id, 'item' => $r->item_name ?: $r->description, 'description' => 'Returned / credited'.(($r->item_name && $r->description && $r->description !== $r->item_name) ? ' - '.$r->description : ''),
                    'qty' => -1 * (float) $r->qty, 'price' => $r->unit_price, 'discount' => null, 'amount' => Money::neg($r->amount)]);
            }
        }
        uasort($groups, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $grand = ['qty' => 0.0, 'amount' => '0.00', 'docs' => 0, 'items' => [], 'lines' => 0];
        foreach ($groups as &$g) {
            usort($g['lines'], fn ($a, $b) => strcmp($a['date'].$a['doc'], $b['date'].$b['doc']));
            uasort($g['items'], fn ($a, $b) => strcasecmp($a['item'], $b['item']));
            $grand['qty'] += $g['qty'];
            $grand['amount'] = Money::add($grand['amount'], $g['amount']);
            $grand['docs'] += count($g['docs']);
            $grand['lines'] += count($g['lines']);
            foreach ($g['items'] as $k => $it) {
                $grand['items'][$k] ??= ['item' => $it['item'], 'qty' => 0.0, 'amount' => '0.00', 'times' => 0];
                $grand['items'][$k]['qty'] += $it['qty'];
                $grand['items'][$k]['amount'] = Money::add($grand['items'][$k]['amount'], $it['amount']);
                $grand['items'][$k]['times'] += $it['times'];
            }
        }
        unset($g);
        uasort($grand['items'], fn ($a, $b) => strcasecmp($a['item'], $b['item']));

        // per item, and the reverse view (each item with the customers who bought it)
        $sold = $this->soldLines($from, $to, $customerId, $itemId);
        $perItem = $byItem = [];
        foreach ($sold as $l) {
            $k = $l['item_id'] ? 'i'.$l['item_id'] : 'd'.mb_strtolower($l['item']);
            $perItem[$k]['name'] = $l['item'];
            $perItem[$k]['id'] = $l['item_id'];
            $perItem[$k]['lines'][] = $l;
            $byItem[$k]['name'] = $l['item'];
            $byItem[$k]['id'] = $l['item_id'];
            $byItem[$k]['lines'][] = $l;
            $byItem[$k]['customers'][$l['customer_id']]['name'] = $l['customer'];
            $byItem[$k]['customers'][$l['customer_id']]['lines'][] = $l;
        }
        $sumItems = [];
        foreach ($perItem as $p) {
            $sumItems[] = ['name' => $p['name'], 'id' => $p['id']] + $this->sumSold($p['lines']);
        }
        usort($sumItems, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $itemGroups = [];
        foreach ($byItem as $bi) {
            $r = [];
            foreach ($bi['customers'] as $cid => $c) {
                $r[] = ['name' => $c['name'], 'id' => $cid] + $this->sumSold($c['lines']);
            }
            usort($r, fn ($a, $b) => (float) $b['amount'] <=> (float) $a['amount']);
            $itemGroups[] = ['name' => $bi['name'], 'id' => $bi['id'], 'rows' => $r, 'total' => $this->sumSold($bi['lines'])];
        }
        usort($itemGroups, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return ['groups' => $groups, 'grand' => $grand, 'sum_items' => $sumItems, 'sum_total' => $this->sumSold($sold), 'item_groups' => $itemGroups];
    }

    // ---- stock expiry -----------------------------------------------------------------------------------------------------------------

    /** Batches of stock expired or expiring on or before $until (with stock left), soonest first. */
    public function stockExpiry(string $until): array
    {
        if (! Tables::has('item_batches')) {
            return [];
        }

        return $this->db()->table('item_batches as b')->join('items as i', 'i.id', '=', 'b.item_id')->where('b.qty', '>', 0)->whereNotNull('b.expiry_date')->where('b.expiry_date', '<=', $until)
            ->orderBy('b.expiry_date')->orderBy('i.name')->get(['b.*', 'i.name as item_name', 'i.purchase_cost'])->all();
    }}
