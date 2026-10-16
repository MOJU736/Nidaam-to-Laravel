@extends('layouts.main')
@section('title', 'New stock adjustment')
@section('content')
@php
    use App\Services\Inventory\StockService as S;
    $catalog = $items->map(fn ($i) => ['id' => $i->id, 'name' => $i->name.($i->sku ? " ({$i->sku})" : ''), 'qty' => (string) $i->quantity_on_hand, 'cost' => (string) $i->purchase_cost])->values();
@endphp
@include('items._tabs')
<a class="back" href="{{ route('items.adjustments') }}">&larr; Stock adjustments</a>
<div class="bar"><h1>New stock adjustment</h1></div>

@if ($items->isEmpty())
    <div class="flash bad">There are no Inventory items yet. Only items of type Inventory have stock to adjust.</div>
@else
<form class="card" method="POST" action="{{ route('items.adjustments.store') }}">
    @csrf
    @error('date')<p class="error">{{ $message }}</p>@enderror
    <p class="muted" style="margin-top:0">Enter how many are really on the shelf now. The difference is worked out and valued at average cost.</p>
    <div class="row2" style="grid-template-columns:1fr 1fr 2fr">
        <div><label for="date" style="margin-top:0">Date</label><input id="date" type="date" name="date" required value="{{ old('date', date('Y-m-d')) }}"></div>
        <div><label for="reason" style="margin-top:0">Reason</label><select id="reason" name="reason">@foreach ($reasons as $k => $l)<option value="{{ $k }}" @selected(old('reason', 'count') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label for="notes" style="margin-top:0">Notes <span class="muted">(optional)</span></label><input id="notes" name="notes" maxlength="500" value="{{ old('notes') }}" placeholder="e.g. Month-end count, 3 boxes water-damaged"></div>
    </div>
    <div class="tbl" style="margin-top:16px">
    <table id="lines">
        <thead><tr><th style="min-width:240px">Item</th><th class="num">On hand</th><th class="num" style="width:140px">Really there</th><th class="num">Change</th><th class="num">Value</th><th></th></tr></thead>
        <tbody></tbody>
        <tfoot><tr><td><button type="button" class="ghost sm" id="add">+ Add item</button> <button type="button" class="ghost sm" id="all">Load every inventory item</button></td><td></td><td></td><td class="num"><strong>Total</strong></td><td class="num"><strong id="total">0.00</strong></td><td></td></tr></tfoot>
    </table>
    </div>
    <div class="actions" style="margin-top:20px"><button>Post adjustment</button><a class="btn ghost" href="{{ route('items.adjustments') }}">Cancel</a></div>
</form>

<script>
(function () {
    var items = @json($catalog), pre = @json($preselect), old = @json(old('lines', [])), body = document.querySelector('#lines tbody'), n = 0;
    function num(v) { v = String(v).replace(/[, ]/g, ''); return v === '' || isNaN(v) ? NaN : parseFloat(v); }
    function fmt(x) { return x.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function find(id) { return items.filter(function (i) { return String(i.id) === String(id); })[0]; }
    function recalc() {
        var total = 0;
        body.querySelectorAll('tr').forEach(function (r) {
            var it = find(r.querySelector('select').value), nq = num(r.querySelector('.nq').value), ch = r.querySelector('.ch'), vl = r.querySelector('.vl');
            if (!it || isNaN(nq)) { ch.textContent = ''; vl.textContent = ''; r.querySelector('.oh').textContent = it ? it.qty : ''; return; }
            var d = Math.round((nq - parseFloat(it.qty)) * 100) / 100, v = Math.round(d * parseFloat(it.cost) * 100) / 100;
            r.querySelector('.oh').textContent = it.qty; ch.textContent = (d > 0 ? '+' : '') + d; ch.style.color = d < 0 ? 'var(--danger)' : ''; vl.textContent = fmt(v); total += v;
        });
        document.getElementById('total').textContent = fmt(total);
    }
    function row(id, nq) {
        var i = n++, tr = document.createElement('tr');
        tr.innerHTML = '<td><select name="lines[' + i + '][item_id]"><option value="">Choose an item...</option>' + items.map(function (it) { return '<option value="' + it.id + '">' + it.name.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</option>'; }).join('') + '</select></td>' +
            '<td class="num oh"></td><td><input class="nq num" name="lines[' + i + '][new_qty]" inputmode="decimal"></td><td class="num ch"></td><td class="num vl"></td><td><button type="button" class="ghost sm rm">&times;</button></td>';
        body.appendChild(tr);
        if (id) tr.querySelector('select').value = id; if (nq !== undefined) tr.querySelector('.nq').value = nq;
        tr.querySelector('select').addEventListener('change', recalc); tr.querySelector('.nq').addEventListener('input', recalc);
        tr.querySelector('.rm').addEventListener('click', function () { tr.remove(); recalc(); });
        recalc();
    }
    document.getElementById('add').addEventListener('click', function () { row(); });
    document.getElementById('all').addEventListener('click', function () { body.innerHTML = ''; items.forEach(function (it) { row(it.id, ''); }); });
    var o = Array.isArray(old) ? old : Object.values(old);
    if (o.length) o.forEach(function (l) { row(l.item_id, l.new_qty); }); else if (pre) row(pre, ''); else row();
})();
</script>
@endif
@endsection
