<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Ledger;
use App\Services\Parties\PartyService;
use App\Services\Reports\ReportService;
use App\Support\Money;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The financial statements and lists. Every report takes its dates from the address, so a link or a download repeats exactly what is on screen. */
class ReportController extends Controller
{
    public function __construct(protected ReportService $reports) {}

    protected function company(): string
    {
        return (string) app(\App\Tenancy\TenantManager::class)->current()?->name;
    }

    /** A CSV download: each row is a list of cells. Money is written as a plain number. */
    protected function csv(string $name, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($c) => $c === null ? '' : $c, $r));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function plain($v): string
    {
        return $v === null || $v === '' ? '' : number_format((float) $v, 2, '.', '');
    }

    /** Statement rows as CSV lines: label, amount. */
    protected function csvRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [str_repeat('  ', max(0, $r['level'] - 1)).$r['label'], $r['type'] === 'group' ? '' : $this->plain($r['amount'])];
        }

        return $out;
    }

    public function index()
    {
        return view('reports.index');
    }

    public function balanceSheet(Request $request)
    {
        $asof = $this->reports->readDate($request->query('asof'), $this->reports->defaultAsOf());
        $bs = $this->reports->balanceSheet($asof);

        if ($request->query('download') === 'csv') {
            $rows = [[$this->company()], ['Balance Sheet'], ['As of '.$this->reports->longDate($asof)], [], ['ASSETS'], ...$this->csvRows($bs['asset_rows']), ['TOTAL ASSETS', $this->plain($bs['assets_total'])], [],
                ['LIABILITIES & EQUITY'], ...$this->csvRows($bs['liab_rows']), ['TOTAL LIABILITIES & EQUITY', $this->plain($bs['liab_eq_total'])]];

            return $this->csv("Balance Sheet $asof.csv", $rows);
        }

        return view('reports.balance-sheet', ['bs' => $bs, 'asof' => $asof, 'reports' => $this->reports]);
    }

    public function profitLoss(Request $request)
    {
        $from = $this->reports->readDate($request->query('from'), $this->reports->fyStart());
        $to = $this->reports->readDate($request->query('to'), $this->reports->defaultAsOf());
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $pl = $this->reports->profitLoss($from, $to);

        if ($request->query('download') === 'csv') {
            $rows = [[$this->company()], ['Profit & Loss'], [$this->reports->longDate($from).' through '.$this->reports->longDate($to)], [],
                ['Income'], ...$this->csvRows($pl['income_rows']), ['Total Income', $this->plain($pl['income_total'])]];
            if ($pl['has_cogs']) {
                $rows = array_merge($rows, [['Cost of Goods Sold']], $this->csvRows($pl['cogs_rows']), [['Total COGS', $this->plain($pl['cogs_total'])], ['Gross Profit', $this->plain($pl['gross'])]]);
            }
            $rows = array_merge($rows, [['Expense']], $this->csvRows($pl['expense_rows']), [['Total Expense', $this->plain($pl['expense_total'])], ['Net Ordinary Income', $this->plain($pl['net_ordinary'])]]);
            if ($pl['has_other']) {
                $rows = array_merge($rows, [['Other Income']], $this->csvRows($pl['oi_rows']), [['Total Other Income', $this->plain($pl['oi_total'])], ['Other Expense']],
                    $this->csvRows($pl['oe_rows']), [['Total Other Expense', $this->plain($pl['oe_total'])], ['Net Other Income', $this->plain($pl['net_other'])]]);
            }
            $rows[] = ['Net Income', $this->plain($pl['net_income'])];

            return $this->csv("Profit and Loss $from to $to.csv", $rows);
        }

        return view('reports.profit-loss', ['pl' => $pl, 'from' => $from, 'to' => $to, 'reports' => $this->reports]);
    }

    public function trialBalance(Request $request)
    {
        $asof = $this->reports->readDate($request->query('asof'), $this->reports->defaultAsOf());
        $tb = $this->reports->trialBalance($asof);

        if ($request->query('download') === 'csv') {
            $rows = [[$this->company()], ['Trial Balance'], ['As of '.$this->reports->longDate($asof)], [], ['Account', 'Debit', 'Credit']];
            foreach ($tb['rows'] as $r) {
                $rows[] = [$r['label'], $this->plain($r['debit']), $this->plain($r['credit'])];
            }
            $rows[] = ['TOTAL', $this->plain($tb['debit']), $this->plain($tb['credit'])];

            return $this->csv("Trial Balance $asof.csv", $rows);
        }

        return view('reports.trial-balance', ['tb' => $tb, 'asof' => $asof, 'reports' => $this->reports]);
    }

    public function statement(Request $request)
    {
        $from = $this->reports->readDate($request->query('from'), $this->reports->fyStart());
        $to = $this->reports->readDate($request->query('to'), $this->reports->defaultAsOf());
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $ledger = ctype_digit((string) $request->query('account')) ? Ledger::find((int) $request->query('account')) : null;
        $st = $ledger ? $this->reports->statement($ledger, $from, $to) : null;

        if ($st && $request->query('download') === 'csv') {
            $n = $st['nature'];
            $rows = [[$this->company()], ['Account Statement: '.$ledger->name], [$this->reports->longDate($from).' through '.$this->reports->longDate($to)], [],
                ['Date', 'Type', 'Num', 'Name', 'Memo', 'Split', $n['in'], $n['out'], 'Balance'], ['Opening balance', '', '', '', '', '', '', '', $this->plain($st['opening'])]];
            foreach ($st['rows'] as $r) {
                $rows[] = [$r['date'], $r['type'], $r['num'], $r['name'], $r['memo'], $r['split'], $this->plain($r['in']), $this->plain($r['out']), $this->plain($r['balance'])];
            }
            $rows[] = ['Total', '', '', '', '', '', $this->plain($st['total_in']), $this->plain($st['total_out']), $this->plain($st['closing'])];

            return $this->csv("Account Statement {$ledger->name} $from to $to.csv", $rows);
        }

        $accounts = Ledger::orderBy('code')->orderBy('name')->get(['id', 'name', 'code'])->mapWithKeys(fn ($l) => [$l->id => trim(($l->code ? $l->code.' - ' : '').$l->name)])->all();

        return view('reports.statement', ['ledger' => $ledger, 'st' => $st, 'from' => $from, 'to' => $to, 'accounts' => $accounts, 'reports' => $this->reports]);
    }

    public function aging(Request $request, string $side)
    {
        abort_unless(in_array($side, ['ar', 'ap'], true), 404);
        $asof = $this->reports->readDate($request->query('asof'), now()->toDateString());
        $ag = $this->reports->aging($side, $asof);
        $title = $side === 'ar' ? 'A/R Aging' : 'A/P Aging';

        if ($request->query('download') === 'csv') {
            $rows = [[$this->company()], [$title], ['As of '.$this->reports->longDate($asof)], [], [$side === 'ar' ? 'Customer' : 'Vendor', ...array_values(ReportService::BUCKETS), 'Credits & other', 'Total']];
            foreach ($ag['rows'] as $r) {
                $rows[] = [$r['name'], ...array_map(fn ($b) => $this->plain($b), array_values($r['buckets'])), $this->plain($r['other']), $this->plain($r['total'])];
            }
            $t = $ag['totals'];
            $rows[] = ['TOTAL', ...array_map(fn ($k) => $this->plain($t[$k]), array_keys(ReportService::BUCKETS)), $this->plain($t['other']), $this->plain($t['total'])];

            return $this->csv(str_replace('/', '', $title)." $asof.csv", $rows);
        }

        return view('reports.aging', ['ag' => $ag, 'side' => $side, 'title' => $title, 'asof' => $asof]);
    }

    public function partyBalance(Request $request, string $side, PartyService $parties)
    {
        abort_unless(in_array($side, ['ar', 'ap'], true), 404);
        $asof = $this->reports->readDate($request->query('asof'), now()->toDateString());
        $c = $parties->config($side);
        $hideZero = (bool) $request->query('hide_zero');
        $rows = [];
        $total = '0.00';
        foreach ($parties->all($side, $asof) as $p) {
            $owed = Money::signed($p->balance, $c['normal']); // positive = owed to us (customers) or by us (vendors)
            if ($hideZero && Money::isZero($owed)) {
                continue;
            }
            $rows[] = ['id' => $p->id, 'name' => $p->name, 'balance' => $owed];
            $total = Money::add($total, $owed);
        }
        $title = $side === 'ar' ? 'Customer Balance' : 'Vendor Balance';

        if ($request->query('download') === 'csv') {
            $out = [[$this->company()], [$title], ['As of '.$this->reports->longDate($asof)], [], [$c['one'], 'Balance']];
            foreach ($rows as $r) {
                $out[] = [$r['name'], $this->plain($r['balance'])];
            }
            $out[] = ['TOTAL', $this->plain($total)];

            return $this->csv("$title $asof.csv", $out);
        }

        return view('reports.party-balance', ['rows' => $rows, 'total' => $total, 'side' => $side, 'title' => $title, 'asof' => $asof, 'c' => $c, 'hideZero' => $hideZero]);
    }

    public function journal(Request $request)
    {
        $from = $this->reports->readDate($request->query('from'), $this->reports->fyStart());
        $to = $this->reports->readDate($request->query('to'), $this->reports->defaultAsOf());
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $entries = $this->reports->journal($from, $to);

        if ($request->query('download') === 'csv') {
            $rows = [[$this->company()], ['Journal'], [$this->reports->longDate($from).' through '.$this->reports->longDate($to)], [], ['Date', 'Type', 'Num', 'Name', 'Account', 'Memo', 'Debit', 'Credit']];
            foreach ($entries as $e) {
                foreach ($e['lines'] as $l) {
                    $rows[] = [$e['date'], $e['type'], $e['num'], $e['name'], $l['account'], $l['memo'], $this->plain($l['debit']), $this->plain($l['credit'])];
                }
            }

            return $this->csv("Journal $from to $to.csv", $rows);
        }

        return view('reports.journal', ['entries' => $entries, 'from' => $from, 'to' => $to]);
    }
}
