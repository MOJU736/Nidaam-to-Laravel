{{-- Report heading: title, dates, print / CSV, and the date filter. $mode = 'asof' | 'range'; $extra = other fields to keep (name => value) --}}
@php($extra = $extra ?? [])
<style>
    .qbr { width:100%; max-width:760px; border-collapse:collapse; font-size:15px }
    .qbr td { padding:5px 8px; border-bottom:1px solid var(--line, #eee) }
    .qbr td.num { text-align:right; white-space:nowrap; width:150px }
    .qbr tr.group td { font-weight:600; padding-top:12px }
    .qbr tr.sec td { font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding-top:18px; border-bottom:2px solid currentColor }
    .qbr tr.total td { font-weight:600; border-top:1px solid #999 }
    .qbr tr.grand td { font-weight:700; border-top:2px solid #333; border-bottom:3px double #333 }
    .qbr .bad { color:#b3261e }
    .rfilter { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap }
    .rfilter label { margin:0 0 4px }
    @media print { nav.top, .noprint { display:none !important } .wide { padding:0 !important; max-width:none !important } .card { box-shadow:none !important; border:0 !important } }
</style>
<div class="bar">
    <div><h1 style="margin:0">{{ $title }}</h1><div class="muted">{{ $subtitle }}</div></div>
    <div class="actions noprint">
        <button type="button" class="ghost" onclick="window.print()">Print</button>
        <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['download' => 'pdf']) }}">PDF</a>
        <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['download' => 'csv']) }}">Excel / CSV</a>
        <a class="btn ghost" href="{{ route('reports.index') }}">All reports</a>
    </div>
</div>
@if (empty($noFilter) && $mode !== 'none')
<form class="card noprint" method="GET" action="{{ url()->current() }}" style="margin-bottom:16px">
    <div class="rfilter">
        @if ($mode === 'asof')
            <div><label for="asof">As of</label><input id="asof" type="date" name="asof" value="{{ $asof }}"></div>
        @else
            <div><label for="from">From</label><input id="from" type="date" name="from" value="{{ $from }}"></div>
            <div><label for="to">To</label><input id="to" type="date" name="to" value="{{ $to }}"></div>
        @endif
        @foreach ($extra as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <button>Show</button>
    </div>
</form>
@endif
