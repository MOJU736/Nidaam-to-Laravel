<?php

namespace App\Http\Controllers;

use App\Services\Reports\ActivityReports;
use App\Services\Reports\AdvancedReports;
use App\Services\Reports\ReportService;
use App\Support\Money;
use Illuminate\Http\Request;

/** Cash flow, general ledger, trial balance in detail, comparisons, P&L by project, profit by item and payments received. */
class AdvancedReportController extends ActivityReportController
{
    public function __construct(ReportService $reports, ActivityReports $activity, protected AdvancedReports $adv)
    {
        parent::__construct($reports, $activity);
    }

    protected function select(string $name, array $opts, string $selected, string $label): string
    {
        $h = '<div><label for="'.$name.'">'.e($label).'</label><select id="'.$name.'" name="'.$name.'">';
        foreach ($opts as $k => $l) {
            $h .= '<option value="'.e($k).'"'.((string) $k === $selected ? ' selected' : '').'>'.e($l).'</option>';
        }

        return $h.'</select></div>';
    }

    protected function sub(string $from, string $to): string
    {
        return $this->reports->longDate($from).' through '.$this->reports->longDate($to);
    }

    // ---- cash flow ----------------------------------------------------------------------------------------------------

    public function cashFlow(Request $request)
    {
        [$from, $to] = $this->range($request);
        $cf = $this->adv->cashFlow($from, $to);
        if ($this->wantsFile($request)) {
            $titles = ['operating' => 'OPERATING ACTIVITIES', 'investing' => 'INVESTING ACTIVITIES', 'financing' => 'FINANCING ACTIVITIES'];
            $rows = [[$this->company()], ['Statement of Cash Flows'], [$this->sub($from, $to)], []];
            foreach ($titles as $k => $t) {
                if ($k !== 'operating' && ! $cf['sections'][$k]) {
                    continue;
                }
                $rows[] = [$t];
                if ($k === 'operating') {
                    $rows[] = ['  Net Income', $this->plain($cf['net_profit'])];
                }
                foreach ($cf['sections'][$k] as $l) {
                    $rows[] = ['  '.$l['label'], $this->plain($l['cash_effect'])];
                }
                $rows[] = ['Net cash provided by '.ucfirst($k).' Activities', $this->plain($cf['totals'][$k])];
            }
            array_push($rows, ['Net cash increase for period', $this->plain($cf['net_change'])], ['Cash at beginning of period', $this->plain($cf['cash_begin'])], ['Cash at end of period', $this->plain($cf['cash_end_computed'])]);

            return $this->file($request, "Cash Flow $from to $to.csv", $rows);
        }

        return view('reports.cash-flow', ['cf' => $cf, 'from' => $from, 'to' => $to, 'reports' => $this->reports]);
    }

    // ---- general ledger --------------------------------------------------------------------------------------------------

    public function generalLedger(Request $request)
    {
        [$from, $to] = $this->range($request);
        $gl = $this->adv->generalLedger($from, $to);
        if ($this->wantsFile($request)) {
            $rows = [[$this->company()], ['General Ledger'], [$this->sub($from, $to)], [], ['Account', 'Date', 'Type', 'Num', 'Name', 'Memo', 'Split', 'Amount', 'Balance']];
            foreach ($gl['accounts'] as $a) {
                $rows[] = [$a['name'], 'Balance at the start', '', '', '', '', '', '', $this->plain($a['st']['opening'])];
                foreach ($a['st']['rows'] as $r) {
                    $rows[] = [$a['name'], $r['date'], $r['type'], $r['num'], $r['name'], $r['memo'], $r['split'], $this->plain($r['in'] ?? Money::neg($r['out'])), $this->plain($r['balance'])];
                }
                $rows[] = ['Total '.$a['name'], '', '', '', '', '', '', $this->plain($a['net']), $this->plain($a['st']['closing'])];
            }

            return $this->file($request, "General Ledger $from to $to.csv", $rows);
        }

        return view('reports.general-ledger', ['gl' => $gl, 'from' => $from, 'to' => $to, 'reports' => $this->reports]);
    }

    // ---- trial balance in detail -----------------------------------------------------------------------------------------

    public function trialBalanceDetail(Request $request)
    {
        $asof = $this->reports->readDate($request->query('asof'), $this->reports->defaultAsOf());
        $tb = $this->adv->trialBalanceDetail($asof);
        if ($this->wantsFile($request)) {
            $rows = [[$this->company()], ['Trial Balance (detail)'], ['As of '.$this->reports->longDate($asof)], [], ['Account', 'Opening balance', 'Debits', 'Credits', 'Closing balance']];
            foreach ($tb['rows'] as $r) {
                $rows[] = [str_repeat('  ', max(0, $r['level'] - 1)).$r['label'], $this->plain($r['v']['op']), $this->plain($r['v']['dr']), $this->plain($r['v']['cr']), $this->plain($r['v']['cl'])];
            }
            $rows[] = ['TOTAL (including opening balances)', '', $this->plain($tb['debits']), $this->plain($tb['credits']), ''];

            return $this->file($request, "Trial Balance detail $asof.csv", $rows);
        }

        return view('reports.trial-balance-detail', ['tb' => $tb, 'asof' => $asof, 'reports' => $this->reports]);
    }

    // ---- comparisons ------------------------------------------------------------------------------------------------------

    public function plCompare(Request $request)
    {
        [$from, $to] = $this->range($request);
        $vs = $request->query('vs') === 'pp' ? 'pp' : 'py';
        $cmp = $vs === 'pp' ? $this->activity->previousPeriod($from, $to) : [$this->activity->yearBefore($from), $this->activity->yearBefore($to)];
        $pl = $this->activity->plCompare($from, $to, $cmp);
        $sub = $this->sub($from, $to).', compared with '.$this->sub($cmp[0], $cmp[1]);

        return $this->columnsReport($request, $pl, 'Profit & Loss Comparison', $sub, $from, $to, "Profit and Loss Comparison $from to $to", true,
            $this->select('vs', ['py' => 'Same dates last year', 'pp' => 'The period before'], $vs, 'Compare with'), ['vs' => $vs]);
    }

    public function plByProject(Request $request)
    {
        [$from, $to] = $this->range($request);
        $pl = $this->activity->plByProject($from, $to);

        return $this->columnsReport($request, $pl, 'Profit & Loss by Project', $this->sub($from, $to), $from, $to, "Profit and Loss by Project $from to $to", false, '', []);
    }

    /** The P&L in columns, as a page or a file (shared by the comparison and the by-project report). */
    protected function columnsReport(Request $request, array $pl, string $title, string $sub, string $from, string $to, string $file, bool $compare, string $before, array $extra)
    {
        if ($this->wantsFile($request)) {
            $head = ['', ...array_values($pl['months']), ...($compare ? ['Change', '%'] : ['Total'])];
            $rows = [[$this->company()], [$title], [$sub], [], $head];
            $line = function ($label, $vals) use ($pl, $compare) {
                $cells = [$label];
                foreach (array_keys($pl['months']) as $k) {
                    $cells[] = $vals === null ? '' : $this->plain($vals[$k] ?? null);
                }
                if ($compare) {
                    $d = $vals === null ? null : Money::sub($vals['cur'] ?? 0, $vals['prev'] ?? 0);
                    $cells[] = $d === null ? '' : $this->plain($d);
                    $cells[] = $vals === null || abs((float) ($vals['prev'] ?? 0)) < 0.005 ? '' : number_format((float) $d / abs((float) $vals['prev']) * 100, 1).'%';
                } else {
                    $cells[] = $vals === null ? '' : $this->plain($vals['_total'] ?? null);
                }

                return $cells;
            };
            foreach ([['income', 'Income'], ['cogs', 'Cost of Goods Sold'], ['expense', 'Expense'], ['other_income', 'Other Income'], ['other_expense', 'Other Expense']] as [$n, $t]) {
                if ($pl['sections'][$n]['rows']) {
                    $rows[] = [$t];
                    foreach ($pl['sections'][$n]['rows'] as $r) {
                        $rows[] = $line(str_repeat('  ', max(0, $r['level'] - 1)).$r['label'], $r['vals']);
                    }
                    $rows[] = $line('Total '.$t, $pl['sections'][$n]['total']);
                    if ($n === 'cogs') {
                        $rows[] = $line('Gross Profit', $pl['gross']);
                    }
                    if ($n === 'expense') {
                        $rows[] = $line('Net Ordinary Income', $pl['net_ordinary']);
                    }
                }
            }
            $rows[] = $line('Net Income', $pl['net_income']);

            return $this->file($request, "$file.csv", $rows);
        }

        return view('reports.pl-by-month', ['pl' => $pl, 'from' => $from, 'to' => $to, 'reports' => $this->reports, 'title' => $title, 'sub' => $sub, 'compare' => $compare, 'before' => $before, 'extra' => $extra,
            'emptyText' => $title === 'Profit & Loss by Project' ? 'No income or expense was put on a project in these dates.' : 'Nothing to show for these dates.']);
    }

    public function bsCompare(Request $request)
    {
        $asof = $this->reports->readDate($request->query('asof'), $this->reports->defaultAsOf());
        $vs = $request->query('vs') === 'pm' ? 'pm' : 'py';
        $other = $vs === 'pm' ? date('Y-m-t', strtotime(substr($asof, 0, 7).'-01 -1 month')) : $this->activity->yearBefore($asof);
        $c = $this->adv->bsCompare($asof, $other);
        if ($this->wantsFile($request)) {
            $rows = [[$this->company()], ['Balance Sheet Comparison'], ['As of '.$this->reports->longDate($asof).', compared with '.$this->reports->longDate($other)], [], ['', $asof, $other, 'Change']];
            $dump = function ($list) use (&$rows) {
                foreach ($list as $r) {
                    $rows[] = $r['type'] === 'group' ? [str_repeat('  ', max(0, $r['level'] - 1)).$r['label']] : [str_repeat('  ', max(0, $r['level'] - 1)).$r['label'], $this->plain($r['a']), $this->plain($r['b']), $this->plain(Money::sub($r['a'], $r['b']))];
                }
            };
            $rows[] = ['ASSETS'];
            $dump($c['asset_rows']);
            $rows[] = ['TOTAL ASSETS', $this->plain($c['assets'][0]), $this->plain($c['assets'][1]), $this->plain(Money::sub($c['assets'][0], $c['assets'][1]))];
            $rows[] = ['LIABILITIES & EQUITY'];
            $dump($c['liab_rows']);
            $rows[] = ['TOTAL LIABILITIES & EQUITY', $this->plain($c['liab_eq'][0]), $this->plain($c['liab_eq'][1]), $this->plain(Money::sub($c['liab_eq'][0], $c['liab_eq'][1]))];

            return $this->file($request, "Balance Sheet Comparison $asof.csv", $rows);
        }

        return view('reports.bs-compare', ['c' => $c, 'asof' => $asof, 'reports' => $this->reports, 'extra' => ['vs' => $vs],
            'before' => $this->select('vs', ['py' => 'Same date last year', 'pm' => 'End of the month before'], $vs, 'Compare with')]);
    }

    // ---- profit by item, payments received ---------------------------------------------------------------------------------

    public function profitByItem(Request $request)
    {
        [$from, $to] = $this->range($request);
        $sort = $request->query('sort') === 'name' ? 'name' : 'profit';
        $p = $this->adv->profitByItem($from, $to, $sort);
        if ($this->wantsFile($request)) {
            $rows = [[$this->company()], ['Profit by Item'], [$this->sub($from, $to)], [], ['Item', 'Qty sold', 'Returned', 'Net amount', 'Cost', 'Profit', 'Margin %']];
            foreach ($p['rows'] as $r) {
                $rows[] = [$r['name'], $r['sold'], $r['returned'], $this->plain($r['amount']), $this->plain($r['cost']), $this->plain($r['profit']), $r['margin'] === null ? '' : number_format($r['margin'], 1, '.', '')];
            }
            $t = $p['total'];
            $rows[] = ['TOTAL', $t['sold'], $t['returned'], $this->plain($t['amount']), $this->plain($t['cost']), $this->plain($t['profit']), $t['margin'] === null ? '' : number_format($t['margin'], 1, '.', '')];

            return $this->file($request, "Profit by Item $from to $to.csv", $rows);
        }

        return view('reports.profit-by-item', ['p' => $p, 'from' => $from, 'to' => $to, 'reports' => $this->reports, 'extra' => ['sort' => $sort],
            'before' => $this->select('sort', ['profit' => 'Most profit first', 'name' => 'Item name'], $sort, 'Sort by')]);
    }

    public function paymentsReceived(Request $request)
    {
        $from = $this->reports->readDate($request->query('from'), date('Y-m-01'));
        $to = $this->reports->readDate($request->query('to'), date('Y-m-d'));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $group = $request->query('by') === 'account' ? 'account' : 'method';
        $pr = $this->adv->paymentsReceived($from, $to, $group);
        if ($this->wantsFile($request)) {
            $rows = [[$this->company()], ['Payments Received'], [$this->sub($from, $to)], [], [$group === 'account' ? 'Account' : 'Method', 'Date', 'Number', 'Customer', $group === 'account' ? 'Method' : 'Account', 'Reference', 'Amount']];
            foreach ($pr['groups'] as $g) {
                foreach ($g['rows'] as $r) {
                    $rows[] = [$g['name'], $r->date, $r->number, $r->customer_name, $group === 'account' ? $r->method_label : $r->account_label, $r->reference, $this->plain($r->amount)];
                }
                $rows[] = ['Total '.$g['name'], '', '', '', '', '', $this->plain($g['total'])];
            }
            $rows[] = ['TOTAL', '', '', '', '', '', $this->plain($pr['total'])];

            return $this->file($request, "Payments Received $from to $to.csv", $rows);
        }

        return view('reports.payments-received', ['pr' => $pr, 'from' => $from, 'to' => $to, 'reports' => $this->reports, 'extra' => ['by' => $group],
            'before' => $this->select('by', ['method' => 'How it was paid', 'account' => 'Where it went'], $group, 'Group by')]);
    }

    // ---- customer items statement ---------------------------------------------------------------------------------------------

    protected function itemsQty($q): string
    {
        return rtrim(rtrim(number_format((float) $q, 3, '.', ''), '0'), '.') ?: '0';
    }

    protected function itemsParams(Request $request): array
    {
        [$from, $to] = $this->range($request);
        $cid = (int) $request->query('customer');
        $iid = (int) $request->query('item');
        $customer = $cid ? \Illuminate\Support\Facades\DB::connection('tenant')->table('customers')->where('id', $cid)->first() : null;
        $item = $iid ? \Illuminate\Support\Facades\DB::connection('tenant')->table('items')->where('id', $iid)->first(['id', 'name']) : null;

        return [$from, $to, $customer ? $cid : 0, $item ? $iid : 0, $customer, $item];
    }

    protected function itemsFile(?object $customer, string $from, string $to, string $ext): string
    {
        return 'Items statement '.($customer ? preg_replace('/[^A-Za-z0-9 _-]/', '', $customer->name) : 'all customers')." $from to $to.$ext";
    }

    /** The statement as a PDF (never with cost or profit: it is for the customer). */
    protected function itemsPdf(array $data, string $from, string $to, ?object $customer, ?object $item): string
    {
        $company = app(\App\Services\Documents\DocumentService::class)->company() + ['logo' => null];

        return app(\App\Services\Documents\PdfService::class)->fromHtml(view('reports.pdf.customer-items', ['ci' => $data, 'from' => $from, 'to' => $to, 'customer' => $customer, 'item' => $item, 'company' => $company, 'qty' => fn ($q) => $this->itemsQty($q)])->render());
    }

    public function customerItems(Request $request)
    {
        [$from, $to, $cid, $iid, $customer, $item] = $this->itemsParams($request);
        $by = $request->query('by') === 'item' ? 'item' : 'customer';
        $profit = $request->query('profit') === '1';
        $data = $this->adv->customerItems($cid, $iid, $from, $to);
        $title = $by === 'item' ? 'Customers by Item' : ($cid ? 'Customer Items Statement' : 'Customer Items Statement - All Customers');
        $sub = $this->sub($from, $to).($item ? ' - '.$item->name.' only' : '');

        if ($request->query('download') === 'pdf') {
            return app(\App\Services\Documents\PdfService::class)->response($this->itemsPdf($data, $from, $to, $customer, $item), $this->itemsFile($customer, $from, $to, 'pdf'), true);
        }
        if ($request->query('download') === 'csv') {
            $rows = [[$this->company()], [$title.($customer ? ': '.$customer->name : '')], [$sub], [], ['Customer', 'Date', 'Invoice / credit note', 'Item', 'Description', 'Qty', 'Unit price', 'Discount', 'Amount']];
            foreach ($data['groups'] as $g) {
                foreach ($g['lines'] as $l) {
                    $rows[] = [$g['name'], $l['date'], $l['doc'], $l['item'], $l['description'], $this->itemsQty($l['qty']), $this->plain($l['price']), $l['discount'] ? $this->plain($l['discount']) : '', $this->plain($l['amount'])];
                }
                $rows[] = ['Total '.$g['name'], '', count($g['docs']).' documents', '', '', $this->itemsQty($g['qty']), '', '', $this->plain($g['amount'])];
            }
            $rows[] = ['TOTAL', '', '', '', '', $this->itemsQty($data['grand']['qty']), '', '', $this->plain($data['grand']['amount'])];
            $rows[] = [];
            $rows[] = ['Items summary'];
            $rows[] = $profit ? ['Item', 'Times', 'Qty sold', 'Returned', 'Net amount', 'Last price', 'Last sold', 'Cost', 'Profit', 'Margin %'] : ['Item', 'Times', 'Qty sold', 'Returned', 'Net amount', 'Last price', 'Last sold'];
            foreach ($data['sum_items'] as $it) {
                $r = [$it['name'], $it['times'], $this->itemsQty($it['sold']), $this->itemsQty($it['returned']), $this->plain($it['amount']), $it['last_price'] !== null ? $this->plain($it['last_price']) : '', (string) $it['last_date']];
                $rows[] = $profit ? array_merge($r, [$this->plain($it['cost']), $this->plain($it['profit']), $it['margin'] === null ? '' : number_format($it['margin'], 1, '.', '')]) : $r;
            }

            return $this->csv($this->itemsFile($customer, $from, $to, 'csv'), $rows);
        }
        $db = \Illuminate\Support\Facades\DB::connection('tenant');
        $before = $this->select('by', ['customer' => 'Items by customer', 'item' => 'Customers by item'], $by, 'Show')
            .$this->select('customer', ['' => 'All customers'] + $db->table('customers')->orderBy('name')->pluck('name', 'id')->all(), $cid ? (string) $cid : '', 'Customer')
            .$this->select('item', ['' => 'All items'] + $db->table('items')->orderBy('name')->pluck('name', 'id')->all(), $iid ? (string) $iid : '', 'Item')
            .'<div><label>&nbsp;</label><label style="font-weight:400;margin:8px 0 0" title="Cost and profit are for you only - they never go on the PDF a customer gets"><input type="checkbox" name="profit" value="1" '.($profit ? 'checked' : '').'> Show cost &amp; profit</label></div>';

        return view('reports.customer-items', ['ci' => $data, 'by' => $by, 'profit' => $profit, 'title' => $title, 'sub' => $sub, 'customer' => $customer, 'item' => $item, 'from' => $from, 'to' => $to, 'before' => $before,
            'qty' => fn ($q) => $this->itemsQty($q), 'email' => (string) ($customer->email ?? '')]);
    }

    public function customerItemsEmail(Request $request)
    {
        [$from, $to, $cid, $iid, $customer, $item] = $this->itemsParams($request);
        $back = route('reports.customer-items', array_filter(['customer' => $cid ?: null, 'item' => $iid ?: null, 'from' => $from, 'to' => $to]));
        if (! $customer) {
            return redirect($back)->with('error', 'Choose one customer to email the statement.');
        }
        $mail = app(\App\Services\Documents\EmailService::class);
        $to_email = $mail->addresses((string) ($request->input('email') ?: $customer->email));
        if (! $to_email) {
            return redirect($back)->with('error', 'Enter a valid email address to send to.');
        }
        if ($request->boolean('save_email') && empty($customer->email) && count($to_email) === 1) {
            \Illuminate\Support\Facades\DB::connection('tenant')->table('customers')->where('id', $cid)->update(['email' => $to_email[0]]);
        }
        $co = (string) (\App\Models\Tenant\Setting::first()->name ?? '');
        $body = '<p>Hello '.e($customer->name).',</p><p>Please find attached the statement of the items you took from '.e($co).' from '.e(Money::date($from)).' to '.e(Money::date($to)).', with the invoice numbers.</p><p>Thank you.<br>'.e($co).'</p>';
        $data = $this->adv->customerItems($cid, $iid, $from, $to);
        $who = $mail->send($to_email, [], [], 'Statement of items - '.$co, $body, $this->itemsPdf($data, $from, $to, $customer, $item), $this->itemsFile($customer, $from, $to, 'pdf'));

        return redirect($back)->with('message', "Items statement emailed to $who.");
    }

    // ---- stock expiry ------------------------------------------------------------------------------------------------------------

    public function stockExpiry(Request $request)
    {
        $days = (int) $request->query('days');
        $days = in_array($days, [30, 60, 90, 180, 365], true) ? $days : 90;
        $rows = $this->adv->stockExpiry(date('Y-m-d', strtotime("+$days days")));
        $today = date('Y-m-d');
        if ($this->wantsFile($request)) {
            $out = [[$this->company()], ['Stock Expiry'], ["Expired, and expiring within $days days"], [], ['Item', 'Batch', 'Expires', 'Days left', 'Units', 'Value at cost']];
            foreach ($rows as $r) {
                $out[] = [$r->item_name, $r->batch_no, $r->expiry_date, (int) floor((strtotime($r->expiry_date) - strtotime($today)) / 86400), rtrim(rtrim((string) $r->qty, '0'), '.'), $this->plain(Money::mul($r->qty, $r->purchase_cost))];
            }

            return $this->file($request, "Stock Expiry $today.csv", $out);
        }

        return view('reports.stock-expiry', ['rows' => $rows, 'days' => $days, 'today' => $today, 'reports' => $this->reports,
            'before' => $this->select('days', [30 => '30 days', 60 => '60 days', 90 => '90 days', 180 => '6 months', 365 => 'A year'], (string) $days, 'Expiring within')]);
    }

    // ---- books health check ------------------------------------------------------------------------------------------------------

    public function health(\App\Services\Reports\HealthService $health, Request $request)
    {
        return view('reports.health', ['findings' => $health->run($request->user()->isAdmin()), 'checkedAt' => now()->format('Y-m-d H:i')]);
    }

    public function healthRepair(\App\Services\Reports\HealthService $health, Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only an administrator can repair the books.');
        $n = $health->repairEntryTotals();
        \App\Services\Accounting\ActivityLog::add('Books Health Check: repaired the saved total of '.$n.' entr'.($n === 1 ? 'y' : 'ies'));

        return redirect()->route('reports.health')->with('message', "Repaired the saved total of $n entr".($n === 1 ? 'y' : 'ies').'.');
    }
}