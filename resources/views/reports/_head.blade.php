{{-- Report heading: title, dates, print / CSV, and the date filter. $mode = 'asof' | 'range'; $extra = other fields to keep (name => value) --}}
@php($extra = $extra ?? [])
<style>
    .qbr { width:100%; max-width:820px; border-collapse:collapse; font-size:15px }
    .qbr td { padding:7px 10px; border-bottom:1px solid var(--line) }
    .qbr td.num { text-align:right; white-space:nowrap; width:160px; font-variant-numeric:tabular-nums; font-family:var(--font-num) }
    .qbr tr:hover td { background:transparent }
    .qbr tr.group td { font-weight:600; padding-top:14px }
    .qbr tr.sec td { font:650 12px var(--font); text-transform:uppercase; letter-spacing:.09em; color:var(--brand-strong); padding-top:22px; border-bottom:2px solid var(--brand) }
    .qbr tr.total td { font-weight:600; border-top:1px solid var(--line-strong); background:var(--bg) }
    .qbr tr.grand td { font-weight:700; font-size:16px; background:var(--brand-soft); color:var(--brand-dark); border-top:0; border-bottom:0 }
    .qbr .bad { color:var(--danger) }
    .rfilter { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap }
    .rfilter label { margin:0 0 4px }
    .rfilter .chips { align-self:center; margin-left:auto }
    .rcoal { display:none }
    @media print { .subnav, .noprint, .side, .topbar { display:none !important } .main { margin:0 !important } .wide { padding:0 !important; max-width:none !important } .card { box-shadow:none !important; border:0 !important } .qbr tr.grand td { background:none; color:inherit; border-top:2px solid #333; border-bottom:3px double #333 } .rcoal { display:block } }
</style>
<div class="bar">
    <div><h1>{{ $title }}</h1><div class="muted">{{ $subtitle }}</div></div>
    <div class="actions noprint">
        <button type="button" class="ghost" onclick="window.print()">Print</button>
        <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['download' => 'pdf']) }}">PDF</a>
        <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['download' => 'csv']) }}">Excel / CSV</a>
        <a class="btn ghost" href="{{ route('reports.index') }}">All reports</a>
    </div>
</div>
@if (empty($noFilter) && $mode !== 'none')
<form class="card noprint" method="GET" action="{{ url()->current() }}" style="margin-bottom:16px;padding:16px 20px" id="rform">
    <div class="rfilter">
        @if ($mode === 'asof')
            <div><label for="asof">As of</label><input id="asof" type="date" name="asof" value="{{ $asof }}"></div>
        @else
            <div><label for="from">From</label><input id="from" type="date" name="from" value="{{ $from }}"></div>
            <div><label for="to">To</label><input id="to" type="date" name="to" value="{{ $to }}"></div>
        @endif
        {!! $before ?? '' !!}
        @foreach ($extra as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <button>Show</button>
        @if ($mode !== 'asof')
            <div class="chips" id="rperiods"><button type="button" data-p="month">This month</button><button type="button" data-p="last-month">Last month</button><button type="button" data-p="quarter">This quarter</button><button type="button" data-p="year">This year</button><button type="button" data-p="last-year">Last year</button></div>
        @endif
    </div>
</form>
@if ($mode !== 'asof')
<script>
(function () {
    var box = document.getElementById('rperiods'); if (!box) { return; }
    function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
    box.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) { return; }
        var n = new Date(), y = n.getFullYear(), m = n.getMonth(), a, z;
        switch (b.dataset.p) {
            case 'month': a = new Date(y, m, 1); z = new Date(y, m + 1, 0); break;
            case 'last-month': a = new Date(y, m - 1, 1); z = new Date(y, m, 0); break;
            case 'quarter': var q = Math.floor(m / 3) * 3; a = new Date(y, q, 1); z = new Date(y, q + 3, 0); break;
            case 'year': a = new Date(y, 0, 1); z = new Date(y, 11, 31); break;
            default: a = new Date(y - 1, 0, 1); z = new Date(y - 1, 11, 31);
        }
        document.getElementById('from').value = iso(a); document.getElementById('to').value = iso(z); document.getElementById('rform').submit();
    });
})();
</script>
@endif
@endif