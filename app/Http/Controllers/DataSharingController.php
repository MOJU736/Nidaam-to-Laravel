<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Services\Auth\Permissions;
use App\Services\DataSharing\DataSharingService;
use App\Services\Documents\PdfService;
use App\Services\Documents\XlsxWriter;
use App\Support\DsView;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Data Sharing: Today, SIM board (cards / list), Clients, a client's page, Money In, Money Out, Profit, Reports (with closed cycles),
 * Plan settings and the monthly presentation. Only for a company with Data Sharing switched on (Settings → Company). Every list page
 * exports to PDF and Excel (?as=pdf / ?as=xlsx). Ported from the legacy Datasharing controller.
 */
class DataSharingController extends Controller
{
    protected array $common = [];

    public function __construct(protected DataSharingService $ds) {}

    // ---- helpers ------------------------------------------------------------------------------

    /** Void, close the books, plans, cycle dates: administrators and managers. */
    protected function canManage(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        $g = app(Permissions::class)->groupName($user);

        return $g === 'admin' || str_contains($g, 'manager');
    }

    protected function manageOnly(Request $request, string $backRoute)
    {
        return $this->canManage($request) ? null : redirect()->route($backRoute)->with('error', 'Only an administrator or a manager can do that.');
    }

    /** The current page address without ?as= (forms send it back so the person returns to where they were). */
    protected function here(Request $request): string
    {
        $qs = preg_replace('/(^|&)as=[^&]*/', '', (string) $request->getQueryString());
        $qs = ltrim((string) $qs, '&');

        return $request->getPathInfo().($qs !== '' ? '?'.$qs : '');
    }

    /** Back to the page the form came from (a path inside Data Sharing), else $default. */
    protected function back(Request $request, string $default, ?string $error = null, ?string $message = null)
    {
        $r = trim((string) $request->input('return'));
        $to = ($r !== '' && preg_match('#^/datasharing(/[A-Za-z0-9_/-]*)?(\?[A-Za-z0-9_=&%.\[\]-]*)?$#', $r)) ? url($r) : (str_contains($default, '/') ? url($default) : route($default));
        $flash = $error ? ['error' => $error] : ($message ? ['message' => $message] : []);

        return redirect($to)->with($flash);
    }

    protected function cycleLabel(array $c): string
    {
        if (substr($c['start'], 8, 2) === '01' && $c['end'] === date('Y-m-t', strtotime($c['start']))) { // a full month is named by its month
            return 'Cycle '.$c['no'].' - '.date('F Y', strtotime($c['start']));
        }

        return 'Cycle '.$c['no'].' - '.Money::date($c['start']).' to '.Money::date($c['end']);
    }

    /** Typed text found in any of $fields - numbers match with or without spaces ("0831231335" = "083 123 1335"). */
    protected function matches(string $q, array $fields): bool
    {
        $q = trim($q);
        if ($q === '') {
            return true;
        }
        $dig = preg_replace('/\D/', '', $q);
        foreach ($fields as $f) {
            if (stripos((string) $f, $q) !== false) {
                return true;
            }
            if (strlen($dig) >= 3 && str_contains(preg_replace('/\D/', '', (string) $f), $dig)) {
                return true;
            }
        }

        return false;
    }

    /** Renders a page, or - for ?as=pdf|xlsx - its tables as a file. */
    protected function page(Request $request, string $view, array $data, string $title, string $subtitle = '')
    {
        $cyc = $this->ds->openCycle();
        $as = $request->query('as');
        $exporting = in_array($as, ['pdf', 'xlsx'], true);
        $co = Setting::first();
        $rid = $exporting ? null : $request->session()->pull('ds_receipt');
        $base = ['ds' => $this->ds, 'cyc' => $cyc, 'canManage' => $this->canManage($request), 'here' => $this->here($request), 'exporting' => $exporting, 'company' => (string) ($co->name ?? ''),
            'notes' => $exporting ? [] : $this->ds->notes(), 'ended' => $exporting ? null : $this->ds->endedError(), 'noCycleBar' => false,
            'receipt' => $rid ? $this->ds->receipt($rid, (string) ($co->name ?? ''), (string) ($co->currency_symbol ?: '$')) : null,
            'fv' => null, 'fCycles' => [], 'fSims' => [], 'plansAll' => []];
        $all = $data + $base;
        if (! $exporting) {
            return view($view, $all + ['title' => $title]);
        }
        $html = view($view, $all + ['title' => $title])->render();
        $file = preg_replace('/[^A-Za-z0-9 _.-]+/', '-', $title);
        if ($as === 'xlsx') {
            $bytes = app(XlsxWriter::class)->make(app(XlsxWriter::class)->rowsFromHtml($html, $title, $subtitle), $title);

            return response($bytes, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$file.'.xlsx"', 'Content-Length' => (string) strlen($bytes)]);
        }
        $css = 'body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#1f2933} h1{font-size:15px;margin:0} p.sub{margin:2px 0 10px;color:#6b7785} table{width:100%;border-collapse:collapse;margin-bottom:10px} th,td{padding:3px 5px;border-bottom:1px solid #d9e0e7;text-align:left} th{font-size:8px;text-transform:uppercase;color:#6b7785} .r{text-align:right} tfoot td{font-weight:bold} tr.ds-group td{background:#eef1f4;font-weight:bold} .ds-card__h h2{font-size:12px;margin:8px 0 3px} .ds-pill{font-size:8px}';
        $pdf = app(PdfService::class)->fromHtml('<html><head><meta charset="utf-8"><style>'.$css.'</style></head><body><h1>'.e($title).'</h1><p class="sub">'.e($subtitle).'</p>'.$html.'</body></html>', 'a4', 'landscape');

        return app(PdfService::class)->response($pdf, $file.'.pdf');
    }

    /** Filters from the address: cycle (id) or from/to dates, sim, method, kind (plan|recharge), cat, q, type (shared|full), plan. */
    protected function readFilters(Request $request, array $cyc): array
    {
        $g = fn ($k) => trim((string) $request->query($k, ''));
        $f = ['cycle_id' => null, 'from' => null, 'to' => null, 'sim_id' => null, 'method' => null, 'kind' => null, 'cat' => null, 'q' => null, 'type' => null, 'plan' => null];
        $f['from'] = $g('from') !== '' ? $this->ds->validDate($g('from')) : null;
        $f['to'] = $g('to') !== '' ? $this->ds->validDate($g('to')) : null;
        if ($f['from'] && $f['to'] && $f['from'] > $f['to']) {
            [$f['from'], $f['to']] = [$f['to'], $f['from']];
        }
        $c = null;
        if (! $f['from'] && ! $f['to']) {
            $c = (int) $g('cycle') ? $this->ds->cycle((int) $g('cycle')) : null;
            $c = $c ?: $cyc;
            $f['cycle_id'] = (int) $c['id'];
        }
        $f['cycle'] = $c;
        if ((int) $g('sim') && $this->ds->sim((int) $g('sim'))) {
            $f['sim_id'] = (int) $g('sim');
        }
        if ($this->ds->methodOk($g('method'))) {
            $f['method'] = $g('method');
        }
        if (in_array($g('kind'), ['plan', 'recharge', 'other'], true)) {
            $f['kind'] = $g('kind');
        }
        $f['cat_in'] = isset(DataSharingService::CATS_IN[$g('cat_in')]) ? $g('cat_in') : null;
        if (isset(DataSharingService::CATS[$g('cat')])) {
            $f['cat'] = $g('cat');
        }
        if (in_array($g('type'), ['shared', 'full'], true)) {
            $f['type'] = $g('type');
        }
        if ((int) $g('plan')) {
            $f['plan'] = (int) $g('plan');
        }
        $f['q'] = $g('q') !== '' ? mb_substr($g('q'), 0, 60) : null;
        $f['label'] = $c ? $this->cycleLabel($c).($c['status'] === 'closed' ? ' (closed)' : '')
            : ($f['from'] && $f['to'] ? 'From '.Money::date($f['from']).' to '.Money::date($f['to']) : ($f['from'] ? 'From '.Money::date($f['from']) : 'Up to '.Money::date($f['to'])));
        $extra = [];
        if ($f['sim_id']) {
            $extra[] = 'SIM '.$this->ds->sim($f['sim_id'])['number'];
        }
        foreach (['method', 'cat', 'q'] as $k) {
            if ($f[$k]) {
                $extra[] = $f[$k];
            }
        }
        if ($f['kind']) {
            $extra[] = $f['kind'] === 'plan' ? 'Plan payments' : ($f['kind'] === 'recharge' ? 'Recharges' : 'Other money in');
        }
        if ($f['cat_in']) {
            $extra[] = $f['cat_in'];
        }
        if ($f['type']) {
            $extra[] = $f['type'] === 'full' ? 'Full SIMs' : 'Shared SIMs';
        }
        if ($f['plan']) {
            $extra[] = $f['plan'].' GB';
        }
        $f['subtitle'] = $f['label'].($extra ? ' - '.implode(', ', $extra) : '');

        return $f;
    }

    /** The filter bar's lists. */
    protected function filterData(array $f): array
    {
        return ['fv' => $f, 'fCycles' => $this->ds->allCycles(), 'fSims' => $this->ds->sims(true)];
    }

    // ---- pages --------------------------------------------------------------------------------

    public function index(Request $request)
    {
        return $this->page($request, 'datasharing.today', ['cd' => $this->ds->cycleData(), 'plansOn' => $this->ds->plans(true), 'pkgDue' => $this->ds->packagesDue(3)], 'Today');
    }

    public function sims(Request $request)
    {
        $cyc = $this->ds->openCycle();
        $cd = $this->ds->cycleData();
        $f = (string) $request->query('f');
        if (! in_array($f, ['all', 'free', 'paid', 'unpaid', 'overdue', 'recharged'], true)) {
            $f = 'all';
        }
        $v = $request->query('v') === 'list' || $request->query('as') ? 'list' : 'cards';
        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $cd['sims'] = array_values(array_filter($cd['sims'], function ($sm) use ($q) {
                if ($this->matches($q, [$sm['number']])) {
                    return true;
                }
                if (! empty($sm['contact']) && $this->matches($q, [$sm['contact']['name'], $sm['contact']['phone'], $sm['contact']['whatsapp']])) {
                    return true;
                }
                foreach ($sm['clients'] as $x) {
                    if ($this->matches($q, [$x['name'], $x['phone'], $x['whatsapp']])) {
                        return true;
                    }
                }

                return false;
            }));
        }
        $ff = $this->readFilters($request, $cyc);
        if ($ff['type'] || $ff['sim_id']) {
            $cd['sims'] = array_values(array_filter($cd['sims'], fn ($sm) => (! $ff['type'] || $sm['kind'] === $ff['type']) && (! $ff['sim_id'] || (int) $sm['id'] === $ff['sim_id'])));
        }
        $names = ['all' => 'All SIMs', 'free' => 'Free slots', 'paid' => 'Paid', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'recharged' => 'Recharged'];

        return $this->page($request, 'datasharing.sims', ['cd' => $cd, 'f' => $f, 'v' => $v, 'q' => $q, 'plans' => $this->ds->plans(true), 'simRows' => $this->ds->sims(), 'plansAll' => $this->ds->plans(), 'noCycleBar' => true] + $this->filterData($ff),
            'SIM list - '.$names[$f], $this->cycleLabel($cyc).($q !== '' ? ' - "'.$q.'"' : '').($ff['type'] ? ' - '.($ff['type'] === 'full' ? 'Full SIMs' : 'Shared SIMs') : ''));
    }

    public function clients(Request $request)
    {
        $cyc = $this->ds->openCycle();
        $cd = $this->ds->cycleData();
        $f = (string) $request->query('f');
        if (! in_array($f, ['all', 'paid', 'unpaid', 'overdue', 'recharged'], true)) {
            $f = 'all';
        }
        $q = trim((string) $request->query('q'));
        $rows = $cd['all'];
        if ($f !== 'all') {
            $rows = array_values(array_filter($rows, fn ($r) => $f === 'recharged' ? $r['rech_n'] > 0 : $r['status'] === $f));
        }
        if ($q !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => $this->matches($q, [$r['name'], $r['phone'], $r['whatsapp'], $r['sim'], $r['gb'].' GB', number_format($r['price'], 2, '.', '')])));
        }
        $ff = $this->readFilters($request, $cyc);
        if ($ff['sim_id']) {
            $sn = $this->ds->sim($ff['sim_id'])['number'];
            $rows = array_values(array_filter($rows, fn ($r) => $r['sim'] === $sn));
        }
        if ($ff['plan']) {
            $rows = array_values(array_filter($rows, fn ($r) => (int) $r['gb'] === $ff['plan']));
        }
        $due = (int) $request->query('due');
        if (in_array($due, [3, 7, 14, 30], true)) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['status'] !== 'paid' && $this->ds->daysFromToday($r['next']) <= $due));
        }
        usort($rows, fn ($a, $b) => strcmp($a['next'], $b['next']));

        return $this->page($request, 'datasharing.clients', ['cd' => $cd, 'rows' => $rows, 'f' => $f, 'q' => $q, 'due' => $due, 'plansAll' => $this->ds->plans(), 'noCycleBar' => true] + $this->filterData($ff),
            'Clients', $this->cycleLabel($cyc).($due ? ' - due within '.$due.' days' : ''));
    }

    public function client(Request $request, int $id)
    {
        $c = $this->ds->client($id);
        if (! $c || $c['status'] !== 'active') {
            return redirect()->route('datasharing.clients')->with('error', 'That client no longer exists.');
        }
        $cyc = $this->ds->openCycle();
        $s = $this->ds->sim($c['sim_id']);
        $cd = $this->ds->cycleData();
        $row = null;
        foreach ($cd['all'] as $r) {
            if ($r['id'] === (int) $c['id']) {
                $row = $r;
            }
        }
        $dests = [];
        foreach ($this->ds->sims() as $o) {
            if ((int) $o['id'] === (int) $s['id']) {
                continue;
            }
            $dests[] = ['id' => (int) $o['id'], 'number' => $o['number'], 'free' => $this->ds->freeSlots($o), 'spare' => $this->ds->spareGb($o)];
        }
        $history = DB::connection('tenant')->table('ds_payments')->where('client_id', (int) $c['id'])->where('void', 0)->orderByDesc('date')->orderByDesc('id')->limit(30)->get()->map(fn ($r) => (array) $r)->all();

        return $this->page($request, 'datasharing.client', ['c' => $c, 's' => $s, 'row' => $row, 'contact' => $s['kind'] === 'full' ? null : $this->ds->simContact($s), 'usage' => $this->ds->usageHistory($c['id']),
            'pays' => $this->ds->payments($cyc['id'], $c['id']), 'history' => $history, 'plans' => $this->ds->plansFor($s['kind'], $c['gb']), 'spare' => $this->ds->spareGb($s), 'dests' => $dests, 'noCycleBar' => true], $c['name']);
    }

    public function money(Request $request)
    {
        $cyc = $this->ds->openCycle();
        $f = $this->readFilters($request, $cyc);
        $f['q_exp'] = $f['q'];
        $rf = $f;
        $rf['q'] = null;
        $rf['kind'] = null;

        return $this->page($request, 'datasharing.money', ['rd' => $this->ds->reportData($rf), 'plansAll' => []] + $this->filterData($f), 'Money Out', $f['subtitle']);
    }

    /** Money In: every client payment and recharge, plus other money in (other income, SIM card sales, owner's money, loans). */
    public function moneyIn(Request $request)
    {
        $cyc = $this->ds->openCycle();
        $f = $this->readFilters($request, $cyc);
        $simNames = [];
        foreach ($this->ds->sims(true) as $sm) {
            $simNames[$sm['id']] = $sm['number'];
        }

        return $this->page($request, 'datasharing.money-in', ['rd' => $this->ds->reportData($f), 'clientsAll' => $this->ds->clients(), 'simNames' => $simNames] + $this->filterData($f), 'Money In', $f['subtitle']);
    }

    public function profit(Request $request)
    {
        $cyc = $this->ds->openCycle();
        $f = $this->readFilters($request, $cyc);
        $cd = $this->ds->cycleData($f['cycle'] ? $f['cycle']['id'] : null);
        $cd['sims'] = array_values(array_filter($cd['sims'], function ($sm) use ($f) {
            if ($f['sim_id'] && (int) $sm['id'] !== $f['sim_id']) {
                return false;
            }

            return ! ($f['type'] && $sm['kind'] !== $f['type']);
        }));

        return $this->page($request, 'datasharing.profit', ['cd' => $cd] + $this->filterData($f), 'Profit by SIM and client', $f['subtitle']);
    }

    public function reports(Request $request)
    {
        $cyc = $this->ds->openCycle();
        $f = $this->readFilters($request, $cyc);
        $c = $f['cycle'] ?: $cyc;

        return $this->page($request, 'datasharing.reports', ['rc' => $c, 'custom' => ! $f['cycle'], 'cd' => $this->ds->cycleData($c['id']), 'rd' => $this->ds->reportData($f), 'closed' => $this->ds->closedCycles()] + $this->filterData($f),
            'Data Sharing report', $f['subtitle']);
    }

    /** The cycle asked for (?cycle=id), else the open one. */
    protected function chosenCycle(Request $request, array $open): array
    {
        $id = (int) $request->query('cycle');
        $c = $id ? $this->ds->cycle($id) : null;

        return $c ?: $open;
    }

    /** The monthly presentation: one slide at a time on screen; PDF = one slide per page. */
    public function deck(Request $request)
    {
        $c = $this->chosenCycle($request, $this->ds->openCycle());
        $cd = $this->ds->cycleData($c['id']);
        $slides = $this->slides($cd);
        $as = $request->query('as');
        if ($as === 'pdf') {
            $html = view('datasharing.deck-pdf', ['slides' => $slides, 'cd' => $cd, 'company' => (string) Setting::first()->name])->render();
            $bytes = app(PdfService::class)->fromHtml($html, 'a4', 'landscape');

            return app(PdfService::class)->response($bytes, 'Cycle '.$cd['no'].' presentation.pdf');
        }
        if ($as === 'xlsx') {
            $html = view('datasharing.deck-tables', ['slides' => $slides])->render();
            $xw = app(XlsxWriter::class);
            $bytes = $xw->make($xw->rowsFromHtml($html, 'Cycle '.$cd['no'].' presentation', $this->cycleLabel($c)), 'Presentation');

            return response($bytes, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="Cycle '.$cd['no'].' presentation.xlsx"', 'Content-Length' => (string) strlen($bytes)]);
        }

        return $this->page($request, 'datasharing.deck', ['rc' => $c, 'cd' => $cd, 'slides' => $slides, 'noCycleBar' => true], 'Cycle '.$cd['no'].' presentation');
    }

    /** Slides as data: title, sub, kpis, bars, table, points (shared by screen and PDF). */
    protected function slides(array $cd): array
    {
        $m = fn ($v) => DsView::m($v);
        $period = Money::date($cd['start']).' - '.Money::date($cd['end']).($cd['closed'] ? ' (closed)' : ' (so far)');
        $company = (string) Setting::first()->name;
        $out = [];
        $margin = $cd['income'] > 0 ? round($cd['net'] / $cd['income'] * 100) : 0;
        $freeSlots = array_sum(array_map(fn ($s) => $s['free_slots'], $cd['sims']));
        $out[] = ['eyebrow' => $company.' - cycle '.$cd['no'], 'title' => 'How the business went', 'sub' => $period,
            'kpis' => [['Income', $m($cd['income']), $m($cd['plan_in']).' plans, '.$m($cd['rech_in']).' recharges', 'good'],
                ['Money out', $m($cd['out']), $m($cd['pkgs'] + $cd['extra']).' data, '.$m($cd['out'] - $cd['pkgs'] - $cd['extra']).' other', ''],
                ['Net profit', $m($cd['net']), $margin.'% of income', $cd['net'] >= 0 ? 'good' : 'bad'],
                ['SIMs / clients', count($cd['sims']).' / '.count($cd['all']), $freeSlots.' free slots', '']]];
        $out[] = ['eyebrow' => 'Money in', 'title' => 'Where the money came from', 'sub' => 'Plan payments and recharges, and how clients paid',
            'bars' => [['title' => 'By type', 'items' => array_merge([['Plan payments', $cd['plan_in']], ['Recharges', $cd['rech_in']]], ! empty($cd['other_in']) ? [['Other income', $cd['other_in']]] : [], ! empty($cd['capital_in']) ? [['Owner money / loans', $cd['capital_in']]] : [])],
                ['title' => 'By payment method', 'alt' => true, 'items' => array_map(fn ($x) => [$x['m'], $x['in']], $cd['methods'])]]];
        $rows = [];
        foreach ($cd['sims'] as $s) {
            $rows[] = [$s['number'], $s['kind'] === 'full' ? 'Full' : 'Shared', ($s['slots'] - $s['free_slots']).' / '.$s['slots'], $s['used'].' / '.$s['gb'], $m($s['income']), $m($s['cost']), $m($s['profit'])];
        }
        $out[] = ['eyebrow' => 'All SIMs', 'title' => 'Every SIM at a glance', 'sub' => 'Income from its clients minus its package and extra data',
            'table' => ['head' => ['SIM', 'Type', 'Clients', 'GB given', 'Income', 'Cost', 'Profit'], 'al' => 'lllrrrr', 'rows' => $rows,
                'foot' => ['Total', '', (string) count($cd['all']), '', $m(array_sum(array_column($cd['sims'], 'income'))), $m(array_sum(array_column($cd['sims'], 'cost'))), $m(array_sum(array_column($cd['sims'], 'profit')))]]];
        $n = count($cd['sims']);
        foreach ($cd['sims'] as $i => $s) {
            $rows = [];
            foreach ($s['clients'] as $x) {
                $rows[] = [$x['name'], $x['gb'].' GB', DsView::STATUS[$x['status']], $x['plan_amt'] ? $m($x['plan_amt']) : '-', $x['rech_n'] ? $x['rech_n'].' - '.$m($x['rech_amt']) : '-', $m($x['income']), $m($x['share'] + $x['extra']), $m($x['profit'])];
            }
            $out[] = ['eyebrow' => 'SIM '.($i + 1).' of '.$n, 'title' => $s['number'],
                'sub' => ($s['kind'] === 'full' ? 'Full SIM' : 'Shared - 5 slots').' - '.$s['used'].' of '.$s['gb'].' GB given - package '.$m($s['pkg']).($s['extra'] ? ' - extra data '.$m($s['extra']) : ''),
                'kpis' => [['Income', $m($s['income']), '', 'good'], ['Cost', $m($s['cost']), '', ''], ['Profit', $m($s['profit']), '', $s['profit'] >= 0 ? 'good' : 'bad'],
                    ['Free slots', (string) $s['free_slots'], $s['free_slots'] ? $m($s['free_cost']).' of data not given out' : 'SIM is full', $s['free_slots'] ? 'warn' : '']],
                'table' => ['head' => ['Client', 'Plan', 'Status', 'Plan paid', 'Recharges', 'Total paid', 'Cost share', 'Profit'], 'al' => 'lllrrrrr', 'rows' => $rows]];
        }
        $rc = array_values(array_filter($cd['all'], fn ($x) => $x['rech_n'] > 0));
        usort($rc, fn ($a, $b) => $b['rech_amt'] <=> $a['rech_amt']);
        $pts = [];
        if ($rc) {
            $pts[] = 'Recharges brought in '.$m($cd['rech_in']).' ('.($cd['income'] > 0 ? round($cd['rech_in'] / $cd['income'] * 100) : 0).'% of income).';
            if ($cd['extra']) {
                $pts[] = 'Extra data bought for recharges cost '.$m($cd['extra']).'.';
            }
            foreach (array_slice(array_filter($rc, fn ($x) => $x['rech_n'] >= 2 || $x['gb'] < 100), 0, 3) as $x) {
                $pts[] = $x['name'].' recharges often: offer a bigger plan than '.$x['gb'].' GB.';
            }
        } else {
            $pts[] = 'Nobody ran out of data early.';
        }
        $out[] = ['eyebrow' => 'Recharges', 'title' => $rc ? count($rc).' client'.(count($rc) > 1 ? 's' : '').' paid a second time' : 'No recharges this cycle', 'sub' => 'They finished their data before the month ended',
            'table' => $rc ? ['head' => ['Client', 'SIM', 'Plan', 'Recharges', 'Recharge money'], 'al' => 'lllrr', 'rows' => array_map(fn ($x) => [$x['name'], $x['sim'], $x['gb'].' GB', (string) $x['rech_n'], $m($x['rech_amt'])], $rc)] : null,
            'points' => $pts];
        $owe = array_values(array_filter($cd['all'], fn ($x) => $x['status'] !== 'paid'));
        usort($owe, fn ($a, $b) => strcmp($a['next'], $b['next']));
        $out[] = ['eyebrow' => 'Still to collect', 'title' => count($owe).' client'.(count($owe) === 1 ? ' has' : 's have').' not paid their plan', 'sub' => $m(array_sum(array_column($owe, 'price'))).' carries over to the next cycle',
            'table' => ['head' => ['Client', 'SIM', 'Plan', 'Status', 'Due', 'Amount'], 'al' => 'lllllr', 'rows' => array_map(fn ($x) => [$x['name'], $x['sim'], $x['gb'].' GB', DsView::STATUS[$x['status']], Money::date($x['next']), $m($x['price'])], $owe)]];
        $pts = [];
        $free = array_filter($cd['sims'], fn ($s) => $s['free_slots'] > 0 && $s['used'] < $s['gb']);
        if ($free) {
            $cheapest = null;
            foreach ($this->ds->plansFor('shared') as $p) {
                if ($cheapest === null || (int) $p['gb'] < (int) $cheapest['gb']) {
                    $cheapest = $p;
                }
            }
            $fill = 0;
            foreach ($free as $s) {
                $fill += $cheapest ? min($s['free_slots'], (int) floor(($s['gb'] - $s['used']) / max(1, (int) $cheapest['gb']))) : 0;
            }
            $pts[] = array_sum(array_column($free, 'free_slots')).' free slots on '.count($free).' SIMs with '.array_sum(array_map(fn ($s) => $s['gb'] - $s['used'], $free)).' GB not given out'.($cheapest && $fill ? ': filling them with '.$cheapest['gb'].' GB plans brings about '.$m($fill * $cheapest['price']).' more a cycle.' : '.');
        }
        foreach ($cd['sims'] as $s) {
            if ($s['profit'] < 0) {
                $pts[] = $s['number'].' lost '.$m(-$s['profit']).': fill its slots or move its clients to another SIM.';
            }
        }
        $over = count(array_filter($cd['all'], fn ($x) => $x['status'] === 'overdue'));
        if ($over) {
            $pts[] = 'Collect from '.$over.' overdue client'.($over > 1 ? 's' : '').' first, or cut them off in MTN.';
        }
        $pts[] = 'Profit on data alone: '.$m($cd['income'] - $cd['pkgs'] - $cd['extra']).'. After all other costs: '.$m($cd['net']).'.';
        if ($cd['moves']) {
            $pts[] = count($cd['moves']).' client move'.(count($cd['moves']) > 1 ? 's' : '').' this cycle: '.implode('; ', array_map(fn ($x) => $x['name'].' '.$x['from'].' to '.$x['to'], $cd['moves'])).'.';
        }
        $out[] = ['eyebrow' => 'Next cycle', 'title' => 'What to do next', 'points' => $pts];

        return $out;
    }

    public function plans(Request $request)
    {
        if ($r = $this->manageOnly($request, 'datasharing.sims')) {
            return $r;
        }
        $plans = $this->ds->plans();
        $on = [];
        foreach ($plans as $p) {
            $on[$p['id']] = $this->ds->planClients($p);
        }

        return $this->page($request, 'datasharing.plans', ['plans' => $plans, 'on' => $on, 'cpg' => $this->ds->costPerGb(), 'noCycleBar' => true], 'Plan settings');
    }

    // ---- actions (POST) -----------------------------------------------------------------------

    public function simAdd(Request $request)
    {
        [, $err] = $this->ds->addSim($request->only(['number', 'kind', 'gb', 'cost', 'method', 'paid_now', 'pkg_until']));

        return $this->back($request, 'datasharing.sims', $err);
    }

    public function simSave(Request $request, int $id)
    {
        $err = $this->ds->saveSim($id, $request->only(['number', 'cost', 'method', 'pkg_until']));
        if (! $err && $request->input('contact_set')) { // only shared SIMs have the main contact field
            $err = $this->ds->setContact($id, $request->only(['contact', 'contact_name', 'contact_phone', 'contact_whatsapp']));
        }

        return $this->back($request, 'datasharing.sims', $err);
    }

    public function simRenew(Request $request, int $id)
    {
        return $this->back($request, 'datasharing.index', $this->ds->renewSim($id, $request->input('until')));
    }

    public function simRetire(Request $request, int $id)
    {
        if ($r = $this->manageOnly($request, 'datasharing.sims')) {
            return $r;
        }

        return $this->back($request, 'datasharing.sims', $this->ds->retireSim($id));
    }

    public function clientAdd(Request $request)
    {
        [, $err] = $this->ds->addClient($request->input('sim_id'), $request->only(['name', 'phone', 'whatsapp', 'gb', 'price', 'next_due']));

        return $this->back($request, 'datasharing.sims', $err);
    }

    public function clientSave(Request $request, int $id)
    {
        return $this->back($request, '/datasharing/client/'.$id, $this->ds->saveClient($id, $request->only(['name', 'phone', 'whatsapp', 'gb', 'price', 'next_due'])));
    }

    public function clientRemove(Request $request, int $id)
    {
        $err = $this->ds->removeClient($id);
        if ($err) {
            return $this->back($request, '/datasharing/client/'.$id, $err);
        }

        return redirect()->route('datasharing.sims');
    }

    public function pay(Request $request, int $id)
    {
        $err = $this->ds->pay($id, $request->only(['amount', 'method', 'date']));
        if (! $err && $request->input('receipt') && $this->ds->lastPaymentId) {
            $request->session()->put('ds_receipt', $this->ds->lastPaymentId);
        }

        return $this->back($request, '/datasharing/client/'.$id, $err);
    }

    public function recharge(Request $request, int $id)
    {
        $err = $this->ds->recharge($id, $request->only(['amount', 'method', 'date', 'src', 'cost', 'cost_method', 'to']));
        if (! $err && $request->input('receipt') && $this->ds->lastPaymentId) {
            $request->session()->put('ds_receipt', $this->ds->lastPaymentId);
        }

        return $this->back($request, '/datasharing/client/'.$id, $err);
    }

    public function move(Request $request)
    {
        return $this->back($request, 'datasharing.sims', $this->ds->moveClients($request->input('from'), (array) $request->input('clients'), $request->input('to'),
            ['number' => $request->input('new_number'), 'cost' => $request->input('new_cost'), 'method' => $request->input('new_method'), 'paid_now' => $request->input('new_paid_now')]));
    }

    protected function voidLog(Request $request, ?string $err, string $what, int $id): void
    {
        if (! $err && ($r = trim((string) $request->input('void_reason'))) !== '') {
            ActivityLog::add('Data Sharing: reason for voiding '.$what.' #'.$id.': '.mb_substr($r, 0, 180));
        }
    }

    public function paymentVoid(Request $request, int $id)
    {
        if ($r = $this->manageOnly($request, 'datasharing.reports')) {
            return $r;
        }
        $err = $this->ds->voidPayment($id);
        $this->voidLog($request, $err, 'payment', $id);

        return $this->back($request, 'datasharing.reports', $err);
    }

    public function expenseAdd(Request $request)
    {
        return $this->back($request, 'datasharing.money', $this->ds->addExpense($request->only(['cat', 'amount', 'method', 'date', 'text'])));
    }

    public function expenseVoid(Request $request, int $id)
    {
        if ($r = $this->manageOnly($request, 'datasharing.money')) {
            return $r;
        }
        $err = $this->ds->voidExpense($id);
        $this->voidLog($request, $err, 'money out', $id);

        return $this->back($request, 'datasharing.money', $err);
    }

    public function incomeAdd(Request $request)
    {
        return $this->back($request, 'datasharing.money-in', $this->ds->addIncome($request->only(['cat', 'amount', 'method', 'date', 'text'])));
    }

    public function incomeVoid(Request $request, int $id)
    {
        if ($r = $this->manageOnly($request, 'datasharing.money-in')) {
            return $r;
        }
        $err = $this->ds->voidIncome($id);
        $this->voidLog($request, $err, 'money in', $id);

        return $this->back($request, 'datasharing.money-in', $err);
    }

    public function usageAdd(Request $request, int $id)
    {
        $err = $this->ds->addUsage($id, $request->only(['value', 'mode', 'date']));
        if (! $err && trim((string) $request->input('value')) === '') {
            $err = 'Type the GB from MTN.';
        }

        return $this->back($request, '/datasharing/client/'.$id, $err);
    }

    public function usageSim(Request $request, int $id)
    {
        $vals = $request->input('u');

        return $this->back($request, 'datasharing.sims', $this->ds->addUsageSim($id, is_array($vals) ? $vals : [], $request->input('mode'), $request->input('date')));
    }

    public function usageDelete(Request $request, int $id)
    {
        if ($r = $this->manageOnly($request, 'datasharing.clients')) {
            return $r;
        }
        [$cid, $err] = $this->ds->deleteUsage($id);

        return $this->back($request, $cid ? '/datasharing/client/'.$cid : 'datasharing.clients', $err);
    }

    public function noteDone(Request $request, int $id)
    {
        $this->ds->noteDone($id);

        return $this->back($request, 'datasharing.index');
    }

    protected function planPost(Request $request): array
    {
        return ['name' => $request->input('name'), 'gb' => $request->input('gb'), 'price' => $request->input('price'), 'full' => $request->input('full') === '1', 'notes' => $request->input('notes')];
    }

    public function planAdd(Request $request)
    {
        if ($r = $this->manageOnly($request, 'datasharing.plans')) {
            return $r;
        }

        return $this->back($request, 'datasharing.plans', $this->ds->addPlan($this->planPost($request)));
    }

    public function planSave(Request $request, int $id)
    {
        if ($r = $this->manageOnly($request, 'datasharing.plans')) {
            return $r;
        }

        return $this->back($request, 'datasharing.plans', $this->ds->updatePlan($id, $this->planPost($request), $request->boolean('apply')));
    }

    public function planToggle(Request $request, int $id)
    {
        if ($r = $this->manageOnly($request, 'datasharing.plans')) {
            return $r;
        }

        return $this->back($request, 'datasharing.plans', $this->ds->togglePlan($id));
    }

    public function planDelete(Request $request, int $id)
    {
        if ($r = $this->manageOnly($request, 'datasharing.plans')) {
            return $r;
        }

        return $this->back($request, 'datasharing.plans', $this->ds->deletePlan($id));
    }

    /** Change plan for several clients at once (upgrade / downgrade). */
    public function planMove(Request $request)
    {
        if ($r = $this->manageOnly($request, 'datasharing.plans')) {
            return $r;
        }
        [$moved, $skipped, $err] = $this->ds->movePlanClients($request->input('from'), $request->input('to'), (array) $request->input('clients'));
        if (! $err && ! $moved && ! $skipped) {
            $err = 'Tick at least one client.';
        }
        if (! $err && $skipped) {
            $err = count($moved).' moved. Not moved - not enough spare GB: '.implode('; ', $skipped).'.';
        }

        return $this->back($request, 'datasharing.plans', $err);
    }

    public function cycleStart(Request $request)
    {
        if ($r = $this->manageOnly($request, 'datasharing.reports')) {
            return $r;
        }

        return $this->back($request, 'datasharing.reports', $this->ds->setCycleStart($request->input('start')));
    }

    public function close(Request $request)
    {
        if ($r = $this->manageOnly($request, 'datasharing.reports')) {
            return $r;
        }
        $closing = $this->ds->openCycle();
        $err = $this->ds->closeCycle();
        if ($err && ! str_starts_with($err, 'Cycle closed')) {
            return $this->back($request, 'datasharing.reports', $err);
        }
        ActivityLog::add('Data Sharing: closed the books for cycle '.$closing['no']);

        return redirect()->route('datasharing.deck', ['cycle' => (int) $closing['id']])->with($err ? ['error' => $err] : []);
    }
}
