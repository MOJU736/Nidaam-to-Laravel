<?php

namespace App\Services\Reports;

use App\Services\Sales\Allocations;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Support\Facades\DB;

/**
 * Reports on what was sold, bought and owed, read from the documents (invoices, bills, credit notes) rather than the
 * ledger. Only issued documents count: a draft is not a real sale yet and a void was cancelled. Ported from the legacy
 * Reports controller and the Sales / Purchases models.
 */
class ActivityReports
{
    public function __construct(protected ReportService $reports, protected Allocations $allocs) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    // ---- open documents ----------------------------------------------------------------

    /** Every issued invoice (bill) with something left to pay, by customer (vendor), with days overdue as of today. */
    public function openDocs(string $side): array
    {
        $ar = $side === 'ar';
        $table = $ar ? 'invoices' : 'bills';
        $pcol = $ar ? 'customer_id' : 'vendor_id';
        $party = $ar ? 'customers' : 'vendors';
        $rows = $this->db()->table($table)->leftJoin($party, "$party.id", '=', "$table.$pcol")->where("$table.status", 'issued')
            ->orderBy("$party.name")->orderBy("$table.date")->orderBy("$table.id")
            ->get(["$table.id", "$table.number", "$table.date", "$table.due_date", "$table.total", "$table.status", "$table.$pcol as party_id", "$party.name as party_name"]);
        $docs = $this->allocs->decorate($side, $rows);

        $today = now()->toDateString();
        $groups = [];
        $grand = ['total' => '0.00', 'paid' => '0.00', 'due' => '0.00', 'overdue' => '0.00', 'count' => 0];
        foreach ($docs as $d) {
            if (! Money::positive($d->balance_due)) {
                continue;
            }
            $due = $d->due_date ? substr((string) $d->due_date, 0, 10) : null;
            $d->days = ($due && $due < $today) ? (int) floor((strtotime($today) - strtotime($due)) / 86400) : 0;
            $d->label = Allocations::docLabel($ar ? 'INV-' : 'BILL-', $d->number);
            $g = &$groups[(int) $d->party_id];
            $g ??= ['id' => (int) $d->party_id, 'name' => $d->party_name ?: '(no name)', 'docs' => [], 'total' => '0.00', 'paid' => '0.00', 'due' => '0.00'];
            $g['docs'][] = $d;
            foreach (['total' => $d->total, 'paid' => $d->paid, 'due' => $d->balance_due] as $k => $v) {
                $g[$k] = Money::add($g[$k], $v);
                $grand[$k] = Money::add($grand[$k], $v);
            }
            unset($g);
            if ($d->days > 0) {
                $grand['overdue'] = Money::add($grand['overdue'], $d->balance_due);
            }
            $grand['count']++;
        }

        return ['groups' => array_values($groups), 'grand' => $grand];
    }

    // ---- sales / purchases by ... ---------------------------------------------------------

    /** Customer credit notes (returns) in the period, issued, not write-offs. */
    protected function creditNotes(string $side, ?string $from, ?string $to)
    {
        return $this->db()->table('credit_notes')->where('side', $side)->where('status', 'issued')->where('kind', 'credit')
            ->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to));
    }

    /** Total sales and invoice count per customer, less returns, biggest first. */
    public function salesByCustomer(string $from, string $to): array
    {
        $rows = $this->db()->table('invoices')->join('customers', 'customers.id', '=', 'invoices.customer_id')->where('invoices.status', 'issued')
            ->whereBetween('invoices.date', [$from, $to])->groupBy('invoices.customer_id', 'customers.name')
            ->selectRaw('invoices.customer_id as id, customers.name, SUM(invoices.total) as total, COUNT(invoices.id) as docs')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'total' => Money::add($r->total, 0), 'docs' => (int) $r->docs, 'credits' => '0.00'])->keyBy('id')->all();

        if (Tables::has('credit_notes')) {
            $names = $this->db()->table('customers')->pluck('name', 'id');
            foreach ($this->creditNotes('ar', $from, $to)->groupBy('party_id')->selectRaw('party_id, SUM(total) as credits')->get() as $c) {
                $id = (int) $c->party_id;
                $rows[$id] ??= ['id' => $id, 'name' => $names[$id] ?? '(no name)', 'total' => '0.00', 'docs' => 0, 'credits' => '0.00'];
                $rows[$id]['credits'] = Money::add($c->credits, 0);
                $rows[$id]['total'] = Money::sub($rows[$id]['total'], $c->credits);
            }
        }
        usort($rows, fn ($a, $b) => Money::cmp($b['total'], $a['total']));

        return array_values($rows);
    }

    public function purchasesByVendor(string $from, string $to): array
    {
        return $this->db()->table('bills')->join('vendors', 'vendors.id', '=', 'bills.vendor_id')->where('bills.status', 'issued')
            ->whereBetween('bills.date', [$from, $to])->groupBy('bills.vendor_id', 'vendors.name')->orderByRaw('SUM(bills.total) DESC')
            ->selectRaw('bills.vendor_id as id, vendors.name, SUM(bills.total) as total, COUNT(bills.id) as docs')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'total' => Money::add($r->total, 0), 'docs' => (int) $r->docs])->all();
    }

    /** Quantity and amount (after line discounts) per item; lines with no item are grouped as "Other / Manual Entry". */
    public function byItem(string $side, string $from, string $to): array
    {
        $ar = $side === 'ar';
        [$lines, $doc, $fk] = $ar ? ['invoice_items', 'invoices', 'invoice_id'] : ['bill_items', 'bills', 'bill_id'];
        $rows = $this->db()->table($lines)->join($doc, "$doc.id", '=', "$lines.$fk")->leftJoin('items', 'items.id', '=', "$lines.item_id")
            ->where("$doc.status", 'issued')->whereBetween("$doc.date", [$from, $to])->groupBy("$lines.item_id", 'items.name')
            ->selectRaw("$lines.item_id as id, items.name, SUM($lines.qty) as qty, SUM($lines.amount - $lines.discount_amount) as amount")->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->id ? $r->name : 'Other / Manual Entry', 'qty' => (string) $r->qty, 'amount' => Money::add($r->amount, 0)])->keyBy('id')->all();

        // returned items come off a sale (quantity and amount)
        if ($ar && Tables::has('credit_note_items')) {
            $returns = $this->db()->table('credit_note_items')->join('credit_notes', 'credit_notes.id', '=', 'credit_note_items.credit_note_id')->leftJoin('items', 'items.id', '=', 'credit_note_items.item_id')
                ->where('credit_notes.side', 'ar')->where('credit_notes.status', 'issued')->where('credit_notes.kind', 'credit')->whereBetween('credit_notes.date', [$from, $to])
                ->groupBy('credit_note_items.item_id', 'items.name')->selectRaw('credit_note_items.item_id as id, items.name, SUM(credit_note_items.qty) as qty, SUM(credit_note_items.amount) as amount')->get();
            foreach ($returns as $r) {
                $id = (int) $r->id;
                $rows[$id] ??= ['id' => $id, 'name' => $id ? $r->name : 'Other / Manual Entry', 'qty' => '0', 'amount' => '0.00'];
                $rows[$id]['qty'] = bcsub($rows[$id]['qty'], (string) $r->qty, 2);
                $rows[$id]['amount'] = Money::sub($rows[$id]['amount'], $r->amount);
            }
        }
        usort($rows, fn ($a, $b) => Money::cmp($b['amount'], $a['amount']));

        return array_values($rows);
    }

    // ---- tax ------------------------------------------------------------------------------

    /** Tax charged on sales (invoices less credit notes) and paid on purchases (bills less supplier credits), by rate. */
    public function taxSummary(string $from, string $to): array
    {
        $names = [];
        if (Tables::has('tax_rates')) {
            foreach ($this->db()->table('tax_rates')->get(['name', 'rate']) as $t) {
                $names[$this->pct($t->rate)] ??= $t->name;
            }
        }

        $collect = function (string $table, array $where, int $sign, string $label, string $partyTable, string $partyKey) use ($from, $to) {
            if (! Tables::has($table, 'tax_total')) {
                return [];
            }

            return $this->db()->table($table)->leftJoin($partyTable, "$partyTable.id", '=', "$table.$partyKey")->where("$table.status", 'issued')
                ->whereBetween("$table.date", [$from, $to])->where("$table.tax_total", '<>', 0)->where(array_combine(array_map(fn ($k) => "$table.$k", array_keys($where)), array_values($where)))
                ->get(["$table.id", "$table.number", "$table.date", "$table.total", "$table.tax_total", "$table.tax_percent", "$partyTable.name as party"])
                ->map(fn ($r) => (object) ((array) $r + ['sign' => $sign, 'label' => $label]))->all();
        };
        $sales = array_merge($collect('invoices', [], 1, 'Invoice', 'customers', 'customer_id'), $collect('credit_notes', ['side' => 'ar'], -1, 'Credit note', 'customers', 'party_id'));
        $purch = array_merge($collect('bills', [], 1, 'Bill', 'vendors', 'vendor_id'), $collect('credit_notes', ['side' => 'ap'], -1, 'Supplier credit', 'vendors', 'party_id'));

        $byRate = [];
        $tot = ['sales_taxable' => '0.00', 'sales_tax' => '0.00', 'purch_taxable' => '0.00', 'purch_tax' => '0.00'];
        foreach (['sales' => $sales, 'purch' => $purch] as $side => $docs) {
            foreach ($docs as $d) {
                $tax = $d->sign > 0 ? Money::add($d->tax_total, 0) : Money::neg($d->tax_total);
                $taxable = Money::sub($d->total, $d->tax_total);
                $taxable = $d->sign > 0 ? $taxable : Money::neg($taxable);
                $d->taxable = $taxable;
                $d->tax = $tax;
                $key = ($d->tax_percent === null || $d->tax_percent === '') ? 'amount' : $this->pct($d->tax_percent);
                $byRate[$key] ??= ['label' => $key === 'amount' ? 'Tax typed as an amount' : (isset($names[$key]) ? $names[$key]." ($key%)" : "$key%"),
                    'sales_taxable' => '0.00', 'sales_tax' => '0.00', 'purch_taxable' => '0.00', 'purch_tax' => '0.00'];
                foreach (["{$side}_taxable" => $taxable, "{$side}_tax" => $tax] as $k => $v) {
                    $byRate[$key][$k] = Money::add($byRate[$key][$k], $v);
                    $tot[$k] = Money::add($tot[$k], $v);
                }
            }
        }
        uksort($byRate, fn ($a, $b) => $a === 'amount' ? 1 : ($b === 'amount' ? -1 : (float) $a <=> (float) $b));

        return ['rates' => array_values($byRate), 'tot' => $tot, 'net' => Money::sub($tot['sales_tax'], $tot['purch_tax']), 'sales' => $sales, 'purch' => $purch];
    }

    protected function pct($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    }

    // ---- stock ------------------------------------------------------------------------------

    /** Every stock item with quantity on hand and its value at cost. */
    public function inventoryValuation(): array
    {
        $items = $this->db()->table('items')->where('type', 'inventory')->orderBy('name')->get(['id', 'name', 'sku', 'quantity_on_hand', 'purchase_cost', 'status']);
        $total = '0.00';
        $rows = [];
        foreach ($items as $i) {
            $value = Money::mul($i->quantity_on_hand, $i->purchase_cost);
            $total = Money::add($total, $value);
            $rows[] = ['id' => (int) $i->id, 'name' => $i->name, 'sku' => $i->sku, 'qty' => (string) $i->quantity_on_hand, 'cost' => Money::add($i->purchase_cost, 0), 'value' => $value, 'active' => (int) $i->status === 1];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** Active stock items at or below their reorder level. */
    public function reorder(): array
    {
        if (! Tables::has('items', 'reorder_level')) {
            return [];
        }

        return $this->db()->table('items')->where('type', 'inventory')->where('status', 1)->whereNotNull('reorder_level')->whereColumn('quantity_on_hand', '<=', 'reorder_level')
            ->orderBy('name')->get(['id', 'name', 'sku', 'quantity_on_hand', 'reorder_level'])->all();
    }

    // ---- profit and loss by month -----------------------------------------------------------

    protected function zeroVals(array $keys): array
    {
        return array_fill_keys([...$keys, '_total'], '0.00');
    }

    protected function addVals(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = Money::add($a[$k] ?? 0, $v);
        }

        return $a;
    }

    protected function subVals(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] = Money::sub($a[$k] ?? 0, $v);
        }

        return $a;
    }

    /** Rows for a node, one amount per month plus a total: heading, accounts, "Total ..." per group. Returns [rows, column totals]. */
    protected function monthRows(array $node, string $normal, array $by, array $keys, int $level): array
    {
        $rows = [];
        $tot = $this->zeroVals($keys);
        foreach ($node['groups'] as $g) {
            [$r, $t] = $this->monthRows($g, $normal, $by, $keys, $level + 1);
            if (! $r) {
                continue;
            }
            $rows[] = ['type' => 'group', 'label' => $g['name'], 'level' => $level, 'vals' => null, 'url' => null];
            array_push($rows, ...$r);
            $rows[] = ['type' => 'total', 'label' => 'Total '.$g['name'], 'level' => $level, 'vals' => $t, 'url' => null];
            $tot = $this->addVals($tot, $t);
        }
        foreach ($node['ledgers'] as $l) {
            if ($l['party'] || ! isset($by[$l['id']])) {
                continue;
            }
            $vals = $this->zeroVals($keys);
            foreach ($by[$l['id']] as $m => $net) {
                $v = $this->reports->side($net, $normal);
                $vals[$m] = $v;
                $vals['_total'] = Money::add($vals['_total'], $v);
            }
            if (! array_filter($vals, fn ($x) => ! Money::isZero($x))) {
                continue;
            }
            $rows[] = ['type' => 'line', 'label' => $l['name'], 'level' => $level, 'vals' => $vals, 'url' => route('reports.statement', ['account' => $l['id'], 'from' => $this->from, 'to' => $this->to])];
            $tot = $this->addVals($tot, $vals);
        }

        return [$rows, $tot];
    }

    /** A section that is one group only (Cost of Goods Sold inside Cost of Goods Sold) lists its accounts straight under the section heading, as the plain P&L does. */
    protected function collapseSingleGroup(array $rows): array
    {
        $n = count($rows);
        if ($n < 3 || $rows[0]['type'] !== 'group' || $rows[$n - 1]['type'] !== 'total' || $rows[$n - 1]['label'] !== 'Total '.$rows[0]['label']) {
            return $rows;
        }
        foreach ($rows as $i => $r) {
            if ($i > 0 && $i < $n - 1 && $r['level'] <= $rows[0]['level']) {
                return $rows; // another top-level row: more than one group
            }
        }

        return array_map(fn ($r) => array_merge($r, ['level' => $r['level'] - 1]), array_slice($rows, 1, $n - 2));
    }

    protected string $from = '';

    protected string $to = '';

    /** Profit & Loss with one column per month. */
    public function plByMonth(string $from, string $to): array
    {
        $months = [];
        $d = new \DateTime(substr($from, 0, 7).'-01');
        $end = new \DateTime($to);
        while ($d <= $end && count($months) < 36) {
            $months[$d->format('Y-m')] = $d->format('M Y');
            $d->modify('+1 month');
        }
        $keys = array_keys($months);
        $this->from = $from;
        $this->to = $to;

        // each account's net (debit positive) per month, in one query
        $by = [];
        $moves = $this->db()->table('entryitems')->join('entries', 'entries.id', '=', 'entryitems.entry_id')->whereBetween('entries.date', [$from, $to])
            ->groupBy('entryitems.ledger_id', DB::raw("DATE_FORMAT(entries.date, '%Y-%m')"))
            ->selectRaw("entryitems.ledger_id as lid, DATE_FORMAT(entries.date, '%Y-%m') as ym, SUM(CASE WHEN entryitems.dc = 'D' THEN entryitems.amount ELSE -entryitems.amount END) as net")->get();
        foreach ($moves as $m) {
            $by[(int) $m->lid][$m->ym] = Money::add($m->net, 0);
        }

        $inc = $this->reports->treeFor('income', $from, $to);
        $exp = $this->reports->treeFor('expense', $from, $to);
        $sec = $this->reports->plSections($inc, $exp);
        $out = [];
        foreach ([['income', 'C', $inc], ['cogs', 'D', null], ['expense', 'D', $exp], ['other_income', 'C', null], ['other_expense', 'D', null]] as [$name, $normal, $loose]) {
            [$rows, $tot] = $this->monthRows(['groups' => $sec[$name], 'ledgers' => $loose['ledgers'] ?? []], $normal, $by, $keys, 1);
            $out[$name] = ['rows' => $this->collapseSingleGroup($rows), 'total' => $tot];
        }
        $gross = $this->subVals($out['income']['total'], $out['cogs']['total']);
        $netOrdinary = $this->subVals($gross, $out['expense']['total']);
        $netOther = $this->subVals($out['other_income']['total'], $out['other_expense']['total']);

        return ['months' => $months, 'sections' => $out, 'gross' => $gross, 'net_ordinary' => $netOrdinary, 'net_other' => $netOther, 'net_income' => $this->addVals($netOrdinary, $netOther),
            'has_cogs' => (bool) $out['cogs']['rows'], 'has_other' => (bool) ($out['other_income']['rows'] || $out['other_expense']['rows'])];
    }
}
