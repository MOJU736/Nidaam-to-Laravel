@extends('layouts.main')
@section('title', 'Reconcile '.$ledger->name)
@section('content')
@php
    use App\Support\Money;
    $inName = $nature['in'].'s'; $outName = $nature['out'].'s';
    $cents = fn ($v) => (int) round(((float) $v) * 100);
    $nIn = count(array_filter($lines, fn ($x) => $x['in'] !== null)); $nOut = count($lines) - $nIn;
    $zero = Money::isZero($totals['difference']);
@endphp
<div class="bar"><div><h1>{{ $ledger->name }}</h1><div class="muted">Statement ending {{ Money::date($rec->statement_date) }} · <a href="{{ route('banking.reconcile', $ledger->id) }}?edit=1">Change statement details</a></div></div><a class="muted" href="{{ route('banking.index') }}">&larr; Reconcile</a></div>
<form method="POST" action="{{ route('banking.rec', $rec->id) }}" id="recForm">
    @csrf
    <div class="card" id="recSum" data-begin="{{ $cents($rec->begin_balance) }}" data-statement="{{ $cents($rec->statement_balance) }}" style="display:flex;gap:28px;flex-wrap:wrap;margin-bottom:14px">
        <div><div class="muted">Statement ending balance</div><strong>{{ Money::format($rec->statement_balance) }}</strong></div>
        <div><div class="muted">Cleared balance</div><strong id="recCleared">{{ Money::format($totals['cleared']) }}</strong>
            <div class="muted" style="font-size:13px">{{ Money::format($rec->begin_balance) }} beginning + <span id="recIn">{{ Money::format($totals['in']) }}</span> in (<span id="recNIn">{{ $totals['n_in'] }}</span>) − <span id="recOut">{{ Money::format($totals['out']) }}</span> out (<span id="recNOut">{{ $totals['n_out'] }}</span>)</div></div>
        <div id="recDiffBox"><div class="muted">Difference</div><strong id="recDiff" style="color:{{ $zero ? 'inherit' : '#b3261e' }}">{{ Money::format($totals['difference']) }}</strong></div>
    </div>

    <div class="actions" style="margin-bottom:10px;flex-wrap:wrap">
        <button type="button" class="ghost sm chip on" data-show="all">All ({{ count($lines) }})</button>
        <button type="button" class="ghost sm chip" data-show="in">{{ $inName }} ({{ $nIn }})</button>
        <button type="button" class="ghost sm chip" data-show="out">{{ $outName }} ({{ $nOut }})</button>
        <input type="search" id="recQ" placeholder="Find amount, name or memo" style="max-width:260px">
        <button type="button" class="ghost sm" data-tick="1">Tick shown</button><button type="button" class="ghost sm" data-tick="0">Untick shown</button>
    </div>

    @if (! $lines)
        <div class="card muted">Nothing to tick: every transaction up to {{ Money::date($rec->statement_date) }} is already cleared.</div>
    @else
        <div class="card tbl"><table id="recTable">
            <thead><tr><th style="width:36px"></th><th>Date</th><th>Type</th><th>No.</th><th>Name / account</th><th>Memo</th><th class="num">{{ $inName }}</th><th class="num">{{ $outName }}</th></tr></thead>
            <tbody>
            @foreach ($lines as $ln)
                @php $isIn = $ln['in'] !== null; @endphp
                <tr data-side="{{ $isIn ? 'in' : 'out' }}" data-cents="{{ $cents($isIn ? $ln['in'] : $ln['out']) }}">
                    <td><input type="checkbox" name="cleared[]" value="{{ $ln['id'] }}" @checked($ln['ticked']) aria-label="Cleared"></td>
                    <td>{{ Money::date($ln['date']) }}</td><td>{{ $ln['type'] }}</td><td><a href="{{ $ln['url'] }}" target="_blank" rel="noopener">{{ $ln['num'] }}</a></td><td>{{ $ln['name'] }}</td><td>{{ $ln['memo'] }}</td>
                    <td class="num">{{ $isIn ? Money::format($ln['in']) : '' }}</td><td class="num">{{ $isIn ? '' : Money::format($ln['out']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
    <p class="muted" style="font-size:13px">Only transactions dated on or before the statement date that are not cleared yet are listed. If something on the statement is missing, record it (for example a bank fee as Money Out) and it will appear.</p>

    <div class="actions" style="margin-top:16px">
        <button type="submit" name="do" value="finish" id="recFinish" @disabled(! $zero) title="The difference must be zero">Finish</button>
        <button type="submit" name="do" value="save" class="ghost">Save for later</button>
        <button type="submit" name="do" value="discard" class="danger" onclick="return confirm('Throw away this reconciliation? Nothing will be marked as cleared.')">Discard</button>
    </div>
</form>
<style>#recTable tbody tr.t td { background: rgba(46,125,50,.10) } .chip.on { background: var(--accent, #2563eb); color: #fff }</style>
<script>
(function () {
    var sum = document.getElementById('recSum'), begin = +sum.dataset.begin, statement = +sum.dataset.statement;
    function money(c) { var neg = c < 0, a = Math.abs(c), s = Math.floor(a / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + ('0' + (a % 100)).slice(-2); return (neg ? '-' : '') + '{{ trim((string) (\App\Models\Tenant\Setting::first()->currency_symbol ?? '')) ?: '$' }}' + s; }
    var rows = Array.prototype.slice.call(document.querySelectorAll('#recTable tbody tr'));
    function recalc() {
        var inC = 0, outC = 0, nIn = 0, nOut = 0;
        rows.forEach(function (r) { var on = r.querySelector('input').checked; r.classList.toggle('t', on); if (!on) return; if (r.dataset.side === 'in') { inC += +r.dataset.cents; nIn++; } else { outC += +r.dataset.cents; nOut++; } });
        var cleared = begin + inC - outC, diff = statement - cleared;
        document.getElementById('recIn').textContent = money(inC); document.getElementById('recOut').textContent = money(outC);
        document.getElementById('recNIn').textContent = nIn; document.getElementById('recNOut').textContent = nOut;
        document.getElementById('recCleared').textContent = money(cleared); document.getElementById('recDiff').textContent = money(diff);
        document.getElementById('recDiff').style.color = diff === 0 ? 'inherit' : '#b3261e';
        document.getElementById('recFinish').disabled = diff !== 0;
    }
    rows.forEach(function (r) {
        r.querySelector('input').addEventListener('change', recalc);
        r.addEventListener('click', function (e) { if (e.target.closest('a, input')) return; var c = r.querySelector('input'); c.checked = !c.checked; recalc(); });
    });
    var show = 'all';
    function filter() {
        var q = document.getElementById('recQ').value.trim().toLowerCase().replace(/[,\s]/g, '');
        rows.forEach(function (r) { var ok = show === 'all' || r.dataset.side === show; if (ok && q) ok = r.textContent.toLowerCase().replace(/[,\s]/g, '').indexOf(q) !== -1; r.style.display = ok ? '' : 'none'; });
    }
    document.querySelectorAll('[data-show]').forEach(function (b) { b.addEventListener('click', function () { show = b.dataset.show; document.querySelectorAll('[data-show]').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); filter(); }); });
    document.getElementById('recQ').addEventListener('input', filter);
    document.querySelectorAll('[data-tick]').forEach(function (b) { b.addEventListener('click', function () { rows.forEach(function (r) { if (r.style.display !== 'none') r.querySelector('input').checked = b.dataset.tick === '1'; }); recalc(); }); });
    recalc();
})();
</script>
@endsection
