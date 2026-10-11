@extends($exporting ? 'datasharing.bare' : 'layouts.main')
@section('title', 'Data Sharing - Profit')
@section('content')
@php
    use App\Support\DsView as V;
    $m = fn ($v) => V::m($v);
    $cl = []; foreach ($cd['sims'] as $sx) { foreach ($sx['clients'] as $x) { $cl[] = $x; } }
    $cd['plan_in'] = array_sum(array_column($cl, 'plan_amt'));
    $cd['rech_in'] = array_sum(array_column($cl, 'rech_amt'));
    $cd['income'] = array_sum(array_column($cd['sims'], 'income'));
    $cd['pkgs'] = array_sum(array_column($cd['sims'], 'pkg'));
    $cd['extra'] = array_sum(array_column($cd['sims'], 'extra'));
    $qs = array_filter(['cycle' => request()->query('cycle'), 'sim' => request()->query('sim'), 'type' => request()->query('type')]);
    $freeCost = array_sum(array_column($cd['sims'], 'free_cost'));
    $freeSlots = array_sum(array_column($cd['sims'], 'free_slots'));
    $pr = $cd['income'] - $cd['pkgs'] - $cd['extra'];
    $bp = [];
    foreach ($cd['sims'] as $sx) { foreach ($sx['clients'] as $x) {
        $k = $sx['kind'].'|'.(int) $x['gb'];
        if (! isset($bp[$k])) { $bp[$k] = ['kind' => $sx['kind'], 'gb' => (int) $x['gb'], 'n' => 0, 'in' => 0, 'cost' => 0, 'profit' => 0]; }
        $bp[$k]['n']++; $bp[$k]['in'] += $x['income']; $bp[$k]['cost'] += $x['share'] + $x['extra']; $bp[$k]['profit'] += $x['profit'];
    } }
    uasort($bp, fn ($a, $b) => $b['profit'] <=> $a['profit']);
@endphp
@include('datasharing._style')
<section class="ds">
    @unless ($exporting)
    <div class="ds-ph"><div><h1>Profit</h1><p>{{ $fv['subtitle'] }} &middot; every SIM with its clients</p></div><div class="ds-tools">@include('datasharing._export', ['route' => 'datasharing.profit', 'qs' => $qs])</div></div>
    @include('datasharing._top')
    @include('datasharing._filters', ['fltAction' => 'datasharing.profit', 'fltFields' => ['cycle', 'type', 'sim'], 'fltKeep' => []])
    <div class="ds-kpis">
        <div class="ds-kpi good"><small>Income</small><strong>{{ $m($cd['income']) }}</strong><span>{{ $m($cd['plan_in']) }} plans + {{ $m($cd['rech_in']) }} recharges</span></div>
        <div class="ds-kpi"><small>SIM costs</small><strong>{{ $m($cd['pkgs'] + $cd['extra']) }}</strong><span>{{ $m($cd['pkgs']) }} packages + {{ $m($cd['extra']) }} extra data</span></div>
        <div class="ds-kpi {{ $pr >= 0 ? 'good' : 'bad' }}"><small>Data profit</small><strong>{{ $m($pr) }}</strong><span>{{ $cd['income'] > 0 ? round($pr / $cd['income'] * 100) : 0 }}% of income</span></div>
        <div class="ds-kpi warn"><small>Data not given out</small><strong>{{ $m($freeCost) }}</strong><span>{{ $freeSlots }} free slots to sell</span></div>
    </div>
    @endunless
    <div class="ds-card"><div class="ds-tw">
        <table class="ds-table">
            <thead><tr><th>SIM / client</th><th class="r">GB</th><th class="r">Plan paid</th><th class="r">Recharges</th><th class="r">Income</th><th class="r">Cost share</th><th class="r">Extra data</th><th class="r">Profit</th></tr></thead>
            <tbody>
            @foreach ($cd['sims'] as $s)
                <tr class="ds-group"><td>{{ $s['number'] }} <small>&middot; {{ $s['kind'] === 'full' ? 'Full SIM' : 'Shared' }}{{ ! empty($s['contact']) ? ' · main contact '.$s['contact']['name'] : '' }}</small></td><td class="r">{{ (int) $s['used'] }}/{{ (int) $s['gb'] }}</td><td></td><td></td><td class="r">{{ $m($s['income']) }}</td><td class="r">{{ $m($s['pkg']) }}</td><td class="r">{{ $m($s['extra']) }}</td><td class="r {{ $s['profit'] < 0 ? 'bad' : 'good' }}">{{ $m($s['profit']) }}</td></tr>
                @foreach ($s['clients'] as $x)
                    <tr><td>&nbsp;&nbsp;{{ $x['name'] }}{{ ! empty($x['main']) ? ($exporting ? ' (main contact)' : V::star($x)) : '' }}</td><td class="r">{{ (int) $x['gb'] }}</td><td class="r">{{ $m($x['plan_amt']) }}</td><td class="r">{{ $x['rech_n'] ? $x['rech_n'].' · '.$m($x['rech_amt']) : '–' }}</td><td class="r">{{ $m($x['income']) }}</td><td class="r">{{ $m($x['share']) }}</td><td class="r">{{ $x['extra'] ? $m($x['extra']) : '–' }}</td><td class="r"><b>{{ $m($x['profit']) }}</b></td></tr>
                @endforeach
                @if ($s['free_cost'] > 0)<tr class="ds-free"><td>&nbsp;&nbsp;Not given out ({{ (int) ($s['gb'] - $s['used']) }} GB)</td><td></td><td></td><td></td><td></td><td class="r">{{ $m($s['free_cost']) }}</td><td></td><td class="r">{{ $m(-$s['free_cost']) }}</td></tr>@endif
            @endforeach
            @if (! $cd['sims'])<tr><td colspan="8" class="ds-empty">No SIMs yet.</td></tr>@endif
            </tbody>
            <tfoot><tr><td>Total</td><td></td><td class="r">{{ $m($cd['plan_in']) }}</td><td class="r">{{ $m($cd['rech_in']) }}</td><td class="r">{{ $m(array_sum(array_column($cd['sims'], 'income'))) }}</td><td class="r">{{ $m(array_sum(array_column($cd['sims'], 'pkg'))) }}</td><td class="r">{{ $m(array_sum(array_column($cd['sims'], 'extra'))) }}</td><td class="r">{{ $m(array_sum(array_column($cd['sims'], 'profit'))) }}</td></tr></tfoot>
        </table>
    </div></div>
    @if ($bp && ! $exporting)
    <div class="ds-card"><div class="ds-card__h"><h2>Profit by plan</h2><span class="ds-hint">Which plans earn most this cycle</span></div><div class="ds-tw">
        <table class="ds-table"><thead><tr><th>Plan</th><th class="r">Clients</th><th class="r">Income</th><th class="r">Cost share</th><th class="r">Profit</th><th class="r">Profit per client</th></tr></thead><tbody>
        @foreach ($bp as $r)<tr><td>{{ $ds->planName($r['gb']) }} <small class="ds-hint">{{ $r['kind'] === 'full' ? 'Full SIM' : 'Slot' }}</small></td><td class="r">{{ (int) $r['n'] }}</td><td class="r">{{ $m($r['in']) }}</td><td class="r">{{ $m($r['cost']) }}</td><td class="r {{ $r['profit'] < 0 ? 'bad' : 'good' }}"><b>{{ $m($r['profit']) }}</b></td><td class="r">{{ $m($r['profit'] / max(1, $r['n'])) }}</td></tr>@endforeach
        </tbody></table></div></div>
    @endif
    @unless ($exporting)<p class="ds-hint">Each SIM package is shared out by GB: a 50 GB client carries 50/200 of the package. "Not given out" is the part of the package nobody pays for yet. Other costs (transport, airtime) are in Reports.</p>@endunless
</section>
@endsection
