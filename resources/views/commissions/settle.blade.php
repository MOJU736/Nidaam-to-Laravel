@extends('layouts.main')
@section('title', 'Settle '.$t['label'])
@section('content')
@php $m = fn ($v) => \App\Support\Money::format($v); @endphp
<div class="bar"><div><h1 style="margin:0">Settle {{ $t['label'] }}</h1><div class="muted">{{ $t['party_name'] }} · received {{ \App\Support\Money::date($t['date']) }} · {{ $m($t['received_amount']) }}</div></div><a class="btn ghost" href="{{ route('commissions.index') }}">Back</a></div>
<form class="card" method="POST" action="{{ route('commissions.settle', $t['id']) }}" style="max-width:640px" id="sf">@csrf
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <div class="row2">
        <div><label for="settle_date">Date settled</label><input id="settle_date" type="date" name="settle_date" min="{{ $t['date'] }}" value="{{ old('settle_date', date('Y-m-d')) }}" required></div>
        <div><label for="remit_ledger_id">Paid out from</label><select id="remit_ledger_id" name="remit_ledger_id" required><option value="">Choose…</option>@foreach ($banks as $b)<option value="{{ $b['id'] }}" @selected((int) old('remit_ledger_id', $t['received_ledger_id']) === $b['id'])>{{ $b['name'] }}</option>@endforeach</select></div>
    </div>
    <div class="row2">
        <div><label for="commission_percent">Commission %</label><input id="commission_percent" name="commission_percent" inputmode="decimal" value="{{ old('commission_percent', (float) $s->default_percent > 0 ? rtrim(rtrim((string) $s->default_percent, '0'), '.') : '') }}" placeholder="e.g. 10"></div>
        <div><label for="commission_amount">or Commission amount</label><input id="commission_amount" name="commission_amount" inputmode="decimal" value="{{ old('commission_amount') }}"></div>
    </div>
    <p class="muted" id="sum" style="margin:10px 0 0"></p>
    @if ((float) $s->settle_tax_percent > 0)<p class="muted" style="margin:4px 0 0">Tax on the commission: {{ rtrim(rtrim((string) $s->settle_tax_percent, '0'), '.') }}% (posted to Tax Expense / Taxes Payable).</p>@endif
    <div class="actions" style="margin-top:18px"><button>Settle</button></div>
</form>
<script>
(function () {
    var rec = {{ (float) $t['received_amount'] }}, p = document.getElementById('commission_percent'), a = document.getElementById('commission_amount'), s = document.getElementById('sum'), sym = {{ \Illuminate\Support\Js::from(preg_replace('/[0-9.,]+$/', '', $m('0'))) }};
    var f = function (v) { return sym + (Math.round(v * 100) / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); };
    var show = function () { var c = parseFloat((a.value || '').replace(/,/g, '')); if (isNaN(c)) { s.textContent = ''; return; } s.textContent = 'Commission ' + f(c) + ' · the client is sent ' + f(rec - c); };
    p.addEventListener('input', function () { var v = parseFloat(p.value); a.value = isNaN(v) ? '' : (Math.round(rec * v) / 100).toFixed(2); show(); });
    a.addEventListener('input', function () { p.value = ''; show(); }); show();
})();
</script>
@endsection
