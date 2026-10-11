<?php

namespace App\Http\Controllers;

use App\Services\Reports\ActivityReports;
use App\Services\Reports\ReportService;
use Illuminate\Http\Request;

/** Reports read from the documents: what is owed, what was sold and bought, tax, stock, and profit by month. */
class ActivityReportController extends ReportController
{
    public function __construct(ReportService $reports, protected ActivityReports $activity)
    {
        parent::__construct($reports);
    }

    /** The from / to dates of the request, defaulting to this financial year to date. */
    protected function range(Request $request): array
    {
        $from = $this->reports->readDate($request->query('from'), $this->reports->fyStart());
        $to = $this->reports->readDate($request->query('to'), $this->reports->defaultAsOf());

        return $from > $to ? [$to, $from] : [$from, $to];
    }

    /** One generic list page: $cols = [header, key, kind (text|money|qty|int), route name for a link or null]. */
    protected function listPage(Request $request, string $title, string $file, array $cols, array $rows, ?array $totals, string $mode, array $extra = [])
    {
        if ($this->wantsFile($request)) {
            $out = [[$this->company()], [$title], [$extra['subtitle'] ?? ''], [], array_column($cols, 0)];
            foreach ($rows as $r) {
                $out[] = array_map(fn ($c) => in_array($c[2], ['money'], true) ? $this->plain($r[$c[1]] ?? null) : ($r[$c[1]] ?? ''), $cols);
            }
            if ($totals) {
                $out[] = array_map(fn ($c, $i) => $i === 0 ? 'TOTAL' : (isset($totals[$c[1]]) ? ($c[2] === 'money' ? $this->plain($totals[$c[1]]) : $totals[$c[1]]) : ''), $cols, array_keys($cols));
            }

            return $this->file($request, $file.'.csv', $out);
        }

        return view('reports.simple', ['title' => $title, 'cols' => $cols, 'rows' => $rows, 'totals' => $totals, 'mode' => $mode] + $extra);
    }

    public function openDocs(Request $request, string $side)
    {
        abort_unless(in_array($side, ['ar', 'ap'], true), 404);
        $od = $this->activity->openDocs($side);
        $title = $side === 'ar' ? 'Open Invoices' : 'Unpaid Bills';

        if ($this->wantsFile($request)) {
            $rows = [[$this->company()], [$title], ['As of '.$this->reports->longDate(now()->toDateString())], [], [$side === 'ar' ? 'Customer' : 'Vendor', 'Number', 'Date', 'Due date', 'Days overdue', 'Total', 'Paid', 'Balance due']];
            foreach ($od['groups'] as $g) {
                foreach ($g['docs'] as $d) {
                    $rows[] = [$g['name'], $d->label, $d->date, $d->due_date, $d->days ?: '', $this->plain($d->total), $this->plain($d->paid), $this->plain($d->balance_due)];
                }
            }
            $rows[] = ['TOTAL', '', '', '', '', $this->plain($od['grand']['total']), $this->plain($od['grand']['paid']), $this->plain($od['grand']['due'])];

            return $this->file($request, $title.' '.now()->toDateString().'.csv', $rows);
        }

        return view('reports.open-docs', ['od' => $od, 'side' => $side, 'title' => $title]);
    }

    public function salesByCustomer(Request $request)
    {
        [$from, $to] = $this->range($request);
        $rows = $this->activity->salesByCustomer($from, $to);
        $totals = ['total' => array_reduce($rows, fn ($c, $r) => \App\Support\Money::add($c, $r['total']), '0.00'), 'docs' => array_sum(array_column($rows, 'docs')), 'credits' => array_reduce($rows, fn ($c, $r) => \App\Support\Money::add($c, $r['credits']), '0.00')];

        return $this->listPage($request, 'Sales by Customer', "Sales by Customer $from to $to", [['Customer', 'name', 'text', 'customers.show'], ['Invoices', 'docs', 'int', null], ['Returns / credits', 'credits', 'money', null], ['Sales', 'total', 'money', null]],
            $rows, $totals, 'range', ['from' => $from, 'to' => $to, 'subtitle' => $this->reports->longDate($from).' through '.$this->reports->longDate($to), 'note' => 'Issued invoices less customer credit notes (returns).']);
    }

    public function purchasesByVendor(Request $request)
    {
        [$from, $to] = $this->range($request);
        $rows = $this->activity->purchasesByVendor($from, $to);
        $totals = ['total' => array_reduce($rows, fn ($c, $r) => \App\Support\Money::add($c, $r['total']), '0.00'), 'docs' => array_sum(array_column($rows, 'docs'))];

        return $this->listPage($request, 'Purchases by Vendor', "Purchases by Vendor $from to $to", [['Vendor', 'name', 'text', 'vendors.show'], ['Bills', 'docs', 'int', null], ['Purchases', 'total', 'money', null]],
            $rows, $totals, 'range', ['from' => $from, 'to' => $to, 'subtitle' => $this->reports->longDate($from).' through '.$this->reports->longDate($to), 'note' => 'Issued bills.']);
    }

    public function byItem(Request $request, string $side)
    {
        abort_unless(in_array($side, ['ar', 'ap'], true), 404);
        [$from, $to] = $this->range($request);
        $rows = $this->activity->byItem($side, $from, $to);
        $title = $side === 'ar' ? 'Sales by Item' : 'Purchases by Item';
        $totals = ['amount' => array_reduce($rows, fn ($c, $r) => \App\Support\Money::add($c, $r['amount']), '0.00'), 'qty' => (string) array_sum(array_map('floatval', array_column($rows, 'qty')))];

        return $this->listPage($request, $title, "$title $from to $to", [['Item', 'name', 'text', null], ['Quantity', 'qty', 'qty', null], ['Amount', 'amount', 'money', null]],
            $rows, $totals, 'range', ['from' => $from, 'to' => $to, 'subtitle' => $this->reports->longDate($from).' through '.$this->reports->longDate($to), 'note' => 'Amounts are after line discounts and before tax.']);
    }

    public function tax(Request $request)
    {
        [$from, $to] = $this->range($request);
        $tax = $this->activity->taxSummary($from, $to);

        if ($this->wantsFile($request)) {
            $rows = [[$this->company()], ['Tax Summary'], [$this->reports->longDate($from).' through '.$this->reports->longDate($to)], [], ['Rate', 'Taxable sales', 'Tax on sales', 'Taxable purchases', 'Tax on purchases', 'Net']];
            foreach ($tax['rates'] as $r) {
                $rows[] = [$r['label'], $this->plain($r['sales_taxable']), $this->plain($r['sales_tax']), $this->plain($r['purch_taxable']), $this->plain($r['purch_tax']), $this->plain(\App\Support\Money::sub($r['sales_tax'], $r['purch_tax']))];
            }
            $t = $tax['tot'];
            $rows[] = ['TOTAL', $this->plain($t['sales_taxable']), $this->plain($t['sales_tax']), $this->plain($t['purch_taxable']), $this->plain($t['purch_tax']), $this->plain($tax['net'])];
            $rows[] = [];
            $rows[] = ['Document', 'Number', 'Date', 'Name', 'Taxable', 'Tax'];
            foreach (array_merge($tax['sales'], $tax['purch']) as $d) {
                $rows[] = [$d->label, $d->number, $d->date, $d->party, $this->plain($d->taxable), $this->plain($d->tax)];
            }

            return $this->file($request, "Tax Summary $from to $to.csv", $rows);
        }

        return view('reports.tax-summary', ['tax' => $tax, 'from' => $from, 'to' => $to, 'reports' => $this->reports]);
    }

    public function inventory(Request $request)
    {
        $inv = $this->activity->inventoryValuation();

        return $this->listPage($request, 'Inventory Valuation', 'Inventory Valuation '.now()->toDateString(), [['Item', 'name', 'text', null], ['SKU', 'sku', 'text', null], ['On hand', 'qty', 'qty', null], ['Cost each', 'cost', 'money', null], ['Value at cost', 'value', 'money', null]],
            $inv['rows'], ['value' => $inv['total']], 'none', ['subtitle' => 'As of '.$this->reports->longDate(now()->toDateString()), 'note' => 'Quantity on hand times the average cost of each stock item.']);
    }

    public function reorder(Request $request)
    {
        $rows = array_map(fn ($r) => ['name' => $r->name, 'sku' => $r->sku, 'qty' => (string) $r->quantity_on_hand, 'level' => (string) $r->reorder_level], $this->activity->reorder());

        return $this->listPage($request, 'Reorder', 'Reorder '.now()->toDateString(), [['Item', 'name', 'text', null], ['SKU', 'sku', 'text', null], ['On hand', 'qty', 'qty', null], ['Reorder level', 'level', 'qty', null]],
            $rows, null, 'none', ['subtitle' => 'Items at or below their reorder level, '.$this->reports->longDate(now()->toDateString()), 'note' => "Set an item's reorder level on its own page (Items, Edit)."]);
    }

    public function plByMonth(Request $request)
    {
        [$from, $to] = $this->range($request);
        $pl = $this->activity->plByMonth($from, $to);

        if ($this->wantsFile($request)) {
            $head = ['', ...array_values($pl['months']), 'Total'];
            $rows = [[$this->company()], ['Profit & Loss by Month'], [$this->reports->longDate($from).' through '.$this->reports->longDate($to)], [], $head];
            $line = fn ($label, $vals) => [$label, ...array_map(fn ($k) => $this->plain($vals[$k] ?? null), array_keys($pl['months'])), $this->plain($vals['_total'] ?? null)];
            $section = function ($name, $title) use (&$rows, $pl, $line) {
                $rows[] = [$title];
                foreach ($pl['sections'][$name]['rows'] as $r) {
                    $rows[] = $r['vals'] ? $line(str_repeat('  ', max(0, $r['level'] - 1)).$r['label'], $r['vals']) : [str_repeat('  ', max(0, $r['level'] - 1)).$r['label']];
                }
                $rows[] = $line('Total '.$title, $pl['sections'][$name]['total']);
            };
            $section('income', 'Income');
            if ($pl['has_cogs']) {
                $section('cogs', 'Cost of Goods Sold');
                $rows[] = $line('Gross Profit', $pl['gross']);
            }
            $section('expense', 'Expense');
            $rows[] = $line('Net Ordinary Income', $pl['net_ordinary']);
            if ($pl['has_other']) {
                $section('other_income', 'Other Income');
                $section('other_expense', 'Other Expense');
                $rows[] = $line('Net Other Income', $pl['net_other']);
            }
            $rows[] = $line('Net Income', $pl['net_income']);

            return $this->file($request, "Profit and Loss by Month $from to $to.csv", $rows);
        }

        return view('reports.pl-by-month', ['pl' => $pl, 'from' => $from, 'to' => $to, 'reports' => $this->reports]);
    }
}
