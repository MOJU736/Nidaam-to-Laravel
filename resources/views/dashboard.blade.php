@extends('layouts.main')
@section('title', 'Dashboard')
@section('content')
@php
    use App\Support\Money;
    use App\Support\Icon;
    $m = fn ($v) => Money::format($v);
    $hour = (int) date('G');
    $hello = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $first = auth()->user()->first_name ?: auth()->user()->username;
    $actions = [['invoices.create', 'New invoice', 'sales'], ['payments.create', 'Receive payment', 'banking'], ['bills.create', 'New bill', 'purchases'], ['expenses.create', 'Record expense', 'payroll']];

    // six-month chart: grouped bars (income, expense) in an SVG that scales to its box
    $months = $d['months'];
    $top = max(1, ...array_map(fn ($x) => max((float) $x['income'], (float) $x['expense']), $months));
    $nice = function ($v) { $p = 10 ** floor(log10($v)); foreach ([1, 2, 4, 6, 8, 10] as $s) { if ($v <= $s * $p) { return $s * $p; } } return 10 * $p; };
    $cap = $nice($top);
    $W = 640; $H = 240; $L = 54; $B = 28; $T = 14; $plotH = $H - $B - $T; $step = ($W - $L - 10) / count($months); $bw = 16;
    $y = fn ($v) => $T + $plotH - ($plotH * min(1, max(0, (float) $v) / $cap));
    $short = fn ($v) => $v >= 1000000 ? round($v / 1000000, 1).'M' : ($v >= 1000 ? round($v / 1000, 1).'k' : round($v));
    $delta = Money::sub($d['this_net'], $d['last_net']);
    $maxOwe = max(1, ...array_map(fn ($o) => (float) $o['amount'], $d['owing'] ?: [['amount' => 1]]));
@endphp
<style>
    .dash-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 16px; }
    .c4 { grid-column: span 4; } .c5 { grid-column: span 5; } .c6 { grid-column: span 6; } .c7 { grid-column: span 7; } .c8 { grid-column: span 8; } .c12 { grid-column: span 12; }
    @media (max-width: 1100px) { .c4, .c5, .c6, .c7, .c8 { grid-column: span 12; } }
    .hero { background: linear-gradient(150deg, #3C3489 0%, #534AB7 70%, #6A60D0 100%); color: #fff; border: 0; position: relative; overflow: hidden; }
    .hero::after { content: ''; position: absolute; width: 300px; height: 300px; right: -110px; top: -120px; border-radius: 50%; border: 36px solid rgba(255, 255, 255, .08); }
    .hero small { color: rgba(255, 255, 255, .75); text-transform: uppercase; letter-spacing: .08em; font-size: 12px; font-weight: 600; }
    .hero .big { font: 700 40px/1.1 var(--font-num); letter-spacing: -.025em; margin: 6px 0 14px; font-variant-numeric: tabular-nums; }
    .hero ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 7px; }
    .hero li { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; color: rgba(255, 255, 255, .92); } .hero li b { font-weight: 600; font-variant-numeric: tabular-nums; }
    .kpi small { color: var(--muted); font-size: 12.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }
    .kpi .v { font: 700 28px/1.15 var(--font-num); letter-spacing: -.02em; margin: 4px 0 2px; font-variant-numeric: tabular-nums; }
    .kpi .sub { font-size: 13px; color: var(--muted); } .kpi .up { color: var(--good); font-weight: 600; } .kpi .down { color: var(--danger); font-weight: 600; }
    .head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; margin-bottom: 12px; } .head h2 { margin: 0; font-size: 16px; } .head a { font-size: 13px; }
    .legend { display: flex; gap: 14px; font-size: 13px; color: var(--muted); } .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 6px; }
    .chart text { fill: var(--muted); font: 11.5px var(--font); } .chart line { stroke: var(--line); } .chart .inc { fill: var(--brand); } .chart .exp { fill: var(--accent); opacity: .85; }
    .attn { display: flex; gap: 12px; align-items: center; padding: 11px 0; border-top: 1px solid var(--line); text-decoration: none; color: inherit; } .attn:first-of-type { border-top: 0; }
    .attn .dot { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; flex: none; background: var(--brand-soft); color: var(--brand-dark); }
    .attn.bad .dot { background: var(--danger-soft); color: var(--danger); } .attn.warn .dot { background: var(--warn-soft); color: var(--warn); } .attn.ok .dot { background: var(--good-soft); color: var(--good); }
    .attn b { display: block; font-size: 14.5px; } .attn span { color: var(--muted); font-size: 13px; } .attn .amt { margin-left: auto; font: 600 15px var(--font-num); white-space: nowrap; font-variant-numeric: tabular-nums; }
    .owe { padding: 9px 0; border-top: 1px solid var(--line); } .owe:first-of-type { border-top: 0; } .owe .top { display: flex; justify-content: space-between; gap: 10px; font-size: 14.5px; } .owe .top b { font-weight: 600; font-variant-numeric: tabular-nums; }
    .owe .track { height: 6px; border-radius: 4px; background: var(--brand-soft); margin-top: 7px; overflow: hidden; } .owe .track i { display: block; height: 100%; background: var(--brand); border-radius: 4px; }
    .act { display: flex; gap: 12px; padding: 10px 0; border-top: 1px solid var(--line); font-size: 14px; } .act:first-of-type { border-top: 0; } .act time { color: var(--muted); font-size: 12.5px; white-space: nowrap; min-width: 82px; }
    .kpi3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; align-content: center; }
    @media (max-width: 1100px) { .kpi3 { grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); } }
    @media (max-width: 560px) { .hero .big { font-size: 32px; } .kpi .v { font-size: 24px; } .kpi3 { grid-template-columns: 1fr; } }
    .quick { display: flex; gap: 8px; flex-wrap: wrap; }
</style>

<div class="bar">
    <div><h1>{{ $hello }}, {{ $first }}</h1><div class="muted">{{ $company?->name }} · {{ date('l, j F Y') }}</div></div>
    <div class="quick">@foreach ($actions as [$r, $label, $icon])@can('page', $r)<a class="btn {{ $loop->first ? '' : 'ghost' }} sm" href="{{ route($r) }}">{{ Icon::svg('plus', 16) }}{{ $label }}</a>@endcan @endforeach</div>
</div>

<div class="dash-grid">
    <div class="card hero c5">
        <small>Cash and bank</small>
        <div class="big">{{ $m($d['cash']) }}</div>
        @if ($d['accounts'])
            <ul>@foreach (array_slice($d['accounts'], 0, 5) as $a)<li><span>{{ $a['name'] }}</span><b>{{ $m($a['amount']) }}</b></li>@endforeach</ul>
            @if (count($d['accounts']) > 5)<div style="margin-top:10px;font-size:13px;color:rgba(255,255,255,.75)">and {{ count($d['accounts']) - 5 }} more account{{ count($d['accounts']) - 5 === 1 ? '' : 's' }}</div>@endif
        @else
            <p style="margin:0;color:rgba(255,255,255,.8)">No bank or cash account yet. Add one under Accounts.</p>
        @endif
    </div>

    <div class="card kpi kpi3 c7">
        <div><small>Owed to you</small><div class="v">{{ $m($d['ar']['due']) }}</div><div class="sub">{{ $d['ar']['count'] }} open invoice{{ $d['ar']['count'] === 1 ? '' : 's' }}@if (Money::positive($d['ar']['overdue'])) · <span class="down">{{ $m($d['ar']['overdue']) }} late</span>@endif</div></div>
        <div><small>You owe</small><div class="v">{{ $m($d['ap']['due']) }}</div><div class="sub">{{ $d['ap']['count'] }} unpaid bill{{ $d['ap']['count'] === 1 ? '' : 's' }}@if (Money::positive($d['ap']['overdue'])) · <span class="down">{{ $m($d['ap']['overdue']) }} late</span>@endif</div></div>
        <div><small>This month</small><div class="v">{{ $m($d['this_net']) }}</div><div class="sub">profit so far @if (Money::cmp($delta, '0') !== 0)· <span class="{{ Money::cmp($delta, '0') > 0 ? 'up' : 'down' }}">{{ Money::cmp($delta, '0') > 0 ? '▲' : '▼' }} {{ $m(Money::cmp($delta, '0') > 0 ? $delta : Money::neg($delta)) }}</span> vs last month @endif</div></div>
    </div>

    <div class="card c8">
        <div class="head"><h2>Income and expenses</h2><div class="legend"><span><i style="background:var(--brand)"></i>Income</span><span><i style="background:var(--accent)"></i>Expenses</span></div></div>
        <svg class="chart" viewBox="0 0 {{ $W }} {{ $H }}" width="100%" role="img" aria-label="Income and expenses by month, last six months">
            @foreach ([0, .25, .5, .75, 1] as $f)
                @php $yy = $T + $plotH - $plotH * $f; @endphp
                <line x1="{{ $L }}" x2="{{ $W - 6 }}" y1="{{ $yy }}" y2="{{ $yy }}"/><text x="{{ $L - 8 }}" y="{{ $yy + 4 }}" text-anchor="end">{{ $short($cap * $f) }}</text>
            @endforeach
            @foreach ($months as $i => $mo)
                @php $cx = $L + $step * $i + $step / 2; $yi = $y($mo['income']); $ye = $y($mo['expense']); @endphp
                <rect class="inc" x="{{ $cx - $bw - 2 }}" y="{{ $yi }}" width="{{ $bw }}" height="{{ max(0, $T + $plotH - $yi) }}" rx="4"><title>{{ $mo['label'] }} income {{ $m($mo['income']) }}</title></rect>
                <rect class="exp" x="{{ $cx + 2 }}" y="{{ $ye }}" width="{{ $bw }}" height="{{ max(0, $T + $plotH - $ye) }}" rx="4"><title>{{ $mo['label'] }} expenses {{ $m($mo['expense']) }}</title></rect>
                <text x="{{ $cx }}" y="{{ $H - 8 }}" text-anchor="middle">{{ $mo['label'] }}</text>
            @endforeach
        </svg>
    </div>

    <div class="card c4">
        <div class="head"><h2>Needs your attention</h2></div>
        @php $any = false; @endphp
        @if (Money::positive($d['ar']['overdue']))@php $any = true; @endphp<a class="attn bad" href="{{ route('reports.ar-aging') }}"><span class="dot">{{ Icon::svg('sales', 18) }}</span><div><b>Customers are late</b><span>Unpaid and past due</span></div><span class="amt">{{ $m($d['ar']['overdue']) }}</span></a>@endif
        @if ($d['soon_n'])@php $any = true; @endphp<a class="attn warn" href="{{ route('reports.ap-aging') }}"><span class="dot">{{ Icon::svg('purchases', 18) }}</span><div><b>{{ $d['soon_n'] }} bill{{ $d['soon_n'] === 1 ? '' : 's' }} due within a week</b><span>Plan the payment</span></div><span class="amt">{{ $m($d['soon']) }}</span></a>@endif
        @if ($d['drafts'])@php $any = true; @endphp<a class="attn" href="{{ route('invoices.index') }}"><span class="dot">{{ Icon::svg('entries', 18) }}</span><div><b>{{ $d['drafts'] }} draft{{ $d['drafts'] === 1 ? '' : 's' }} waiting</b><span>Older than 14 days</span></div></a>@endif
        @unless ($any)<div class="attn ok"><span class="dot">{{ Icon::svg('dashboard', 18) }}</span><div><b>All clear</b><span>Nothing is late or waiting.</span></div></div>@endunless
    </div>

    <div class="card c6">
        <div class="head"><h2>Who owes you most</h2><a href="{{ route('reports.ar-aging') }}">All unpaid</a></div>
        @forelse ($d['owing'] as $o)
            <div class="owe"><div class="top"><span><a href="{{ route('customers.show', $o['id']) }}" style="color:inherit;text-decoration:none">{{ $o['name'] }}</a> <span class="muted" style="font-size:12.5px">· {{ $o['count'] }} invoice{{ $o['count'] === 1 ? '' : 's' }}</span></span><b>{{ $m($o['amount']) }}</b></div><div class="track"><i style="width:{{ round((float) $o['amount'] / $maxOwe * 100) }}%"></i></div></div>
        @empty
            <p class="muted" style="margin:0">Nobody owes you anything right now.</p>
        @endforelse
    </div>

    <div class="card c6">
        <div class="head"><h2>Recent activity</h2></div>
        @forelse ($d['recent'] as $r)
            <div class="act"><time datetime="{{ $r['when'] }}">{{ \Illuminate\Support\Carbon::parse($r['when'])->diffForHumans(null, true, true) }} ago</time><span>{{ $r['what'] }}@if ($r['who']) <span class="muted">· {{ $r['who'] }}</span>@endif</span></div>
        @empty
            <p class="muted" style="margin:0">Nothing has been done yet.</p>
        @endforelse
    </div>
</div>
@endsection
