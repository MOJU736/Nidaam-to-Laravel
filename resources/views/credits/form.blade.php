@extends('layouts.main')
@php
    $isAr = $ui['side'] === 'ar';
    $editing = (bool) $cn;
    $blank = ['item_id' => '', 'source_line_id' => '', 'ledger_id' => '', 'description' => '', 'qty' => '1', 'unit_price' => '', 'restock' => 0];
    $rows = $lines ? array_map(fn ($l) => array_merge($blank, (array) $l), $lines) : [$blank];
@endphp
@section('title', ($editing ? 'Edit ' : 'New ').$ui['name'])
@section('content')
@include('credits._tabs')
<a class="back" href="{{ route($ui['route'].'.index') }}">&larr; Back</a>
<div class="bar"><h1>{{ $editing ? 'Edit '.$ui['name'].' '.$cn->label : 'New '.strtolower($ui['name']) }}</h1></div>

<form class="card" method="POST" id="creditForm" action="{{ $editing ? route($ui['route'].'.update', $cn->id) : route($ui['route'].'.store') }}">
    @csrf
    @if ($editing) @method('PUT') @endif
    @foreach (['party_id', 'date'] as $k)@if ($errors->has($k))<p class="error">{{ $errors->first($k) }}</p>@endif @endforeach
    @if ($source)<input type="hidden" name="source_doc_id" value="{{ $source['id'] }}"><p class="muted" style="margin-top:0">For <a href="{{ route($ui['doc_route'], $source['id']) }}">{{ $source['label'] }}</a>: its lines are copied below at the price actually charged. Remove or change any line so only what is being credited is left.</p>
    @else<p class="muted" style="margin-top:0">{{ $isAr ? 'Money given back to a customer: returned goods, a price correction or goodwill.' : 'Money a supplier gives back: goods returned to them, or an overcharge.' }}</p>@endif

    <div class="row2" style="grid-template-columns:2fr 1fr">
        <div><label for="party_id" style="margin-top:0">{{ ucfirst($ui['party_word']) }} *</label>
            @if ($source)
                <input type="hidden" name="party_id" value="{{ $selectedParty }}"><input value="{{ optional($parties->firstWhere('id', (int) $selectedParty))->name }}" readonly>
            @else
                <select id="party_id" name="party_id" required><option value="">Choose a {{ $ui['party_word'] }}...</option>@foreach ($parties as $p)<option value="{{ $p->id }}" @selected((string) $selectedParty === (string) $p->id)>{{ $p->name }}</option>@endforeach</select>
            @endif</div>
        <div><label for="date" style="margin-top:0">Date *</label><input id="date" type="date" name="date" required value="{{ $date }}"></div>
    </div>

    <h2>Lines <span class="muted" style="font-weight:400;font-size:13px">({{ $isAr ? 'tick "Back to stock" for stock the customer returned in good condition; leave it off for damaged goods or price corrections' : 'tick "Returned" for stock you sent back to the supplier (it leaves your stock); leave it off for an overcharge on goods you keep' }})</span></h2>
    <div class="tbl"><table id="lines">
        <thead><tr><th style="min-width:180px">Item <span class="muted">(optional)</span></th><th style="min-width:160px">Description</th>@if (! $isAr)<th style="min-width:170px">Account</th>@endif<th class="num" style="width:80px">Qty</th><th class="num" style="width:110px">Price</th><th class="num" style="width:100px">Amount</th><th style="width:90px">{{ $isAr ? 'Back to stock' : 'Returned' }}</th><th></th></tr></thead>
        <tbody>
        @foreach ($rows as $i => $l)
            <tr class="cline">
                <td><input type="hidden" name="lines[{{ $i }}][source_line_id]" value="{{ $l['source_line_id'] }}"><select name="lines[{{ $i }}][item_id]" class="it"><option value="">No item</option>@foreach ($catalog as $id => $c)<option value="{{ $id }}" @selected((string) $l['item_id'] === (string) $id)>{{ $c['name'] }}</option>@endforeach</select></td>
                <td><input name="lines[{{ $i }}][description]" class="ds" value="{{ $l['description'] }}" maxlength="255"></td>
                @if (! $isAr)<td><select name="lines[{{ $i }}][ledger_id]" class="ac"><option value="">Choose...</option>@foreach ($accounts as $id => $n)<option value="{{ $id }}" @selected((string) $l['ledger_id'] === (string) $id)>{{ $n }}</option>@endforeach</select><div class="muted inv" style="font-size:12px" hidden>Posted to Inventory Asset</div></td>@endif
                <td><input name="lines[{{ $i }}][qty]" class="qty num" inputmode="decimal" value="{{ $l['qty'] }}"></td>
                <td><input name="lines[{{ $i }}][unit_price]" class="up num" inputmode="decimal" value="{{ $l['unit_price'] }}"></td>
                <td class="num amt">0.00</td>
                <td><label style="margin:0;font-weight:400" class="rs" hidden><input type="checkbox" name="lines[{{ $i }}][restock]" value="1" @checked($l['restock'])> Yes</label></td>
                <td><button type="button" class="ghost sm rm" title="Remove line">&times;</button></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <button type="button" class="ghost sm" id="addLine" style="margin-top:6px">+ Add line</button>

    <table style="margin-top:16px;max-width:520px;margin-left:auto">
        <tr><td class="num">Subtotal</td><td class="num" id="tsub" style="width:120px">0.00</td></tr>
        <tr><td class="num"><span style="margin-right:8px">Tax</span><input name="tax_percent" id="tp" class="num" inputmode="decimal" placeholder="%" style="width:70px;display:inline-block" value="{{ $taxPercent }}">
            @include('settings._tax-picker', ['side' => ($ui['route'] ?? '') === 'supplier-credits' ? 'ap' : 'ar', 'isNew' => ! $editing])
            <input name="tax_total" id="tt" class="num" inputmode="decimal" placeholder="or amount" style="width:100px;display:inline-block" value="{{ $taxTotal }}"></td><td class="num" id="ttax">0.00</td></tr>
        <tr><td class="num"><strong>Total credit</strong></td><td class="num"><strong id="ttot">0.00</strong></td></tr>
    </table>

    <label for="notes">Notes <span class="muted">(the reason; shown on the {{ strtolower($ui['name']) }})</span></label><textarea id="notes" name="notes" rows="2" maxlength="500">{{ $notes }}</textarea>

    <div class="actions" style="margin-top:20px">
        <button type="submit" name="then" value="issue" onclick="return confirm('Issue this {{ strtolower($ui['name']) }}? It will be posted to the books{{ $source ? ' and applied to '.$source['label'] : '' }}.')">Save &amp; issue</button>
        <button type="submit" name="then" value="draft" class="ghost">Save as draft</button>
        <a class="btn ghost" href="{{ $editing ? route($ui['route'].'.show', $cn->id) : ($source ? route($ui['doc_route'], $source['id']) : route($ui['route'].'.index')) }}">Cancel</a>
    </div>
</form>

<script>
(function () {
    var catalog = @json($catalog), isAr = {{ $isAr ? 'true' : 'false' }};
    var body = document.querySelector('#lines tbody');
    function num(v) { v = String(v).replace(/[, ]/g, ''); return v === '' || isNaN(v) ? 0 : parseFloat(v); }
    function r2(x) { return Math.round((x + Number.EPSILON) * 100) / 100; }
    function fmt(x) { return x.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function totals() {
        var sub = 0;
        body.querySelectorAll('tr.cline').forEach(function (r) { var a = r2(num(r.querySelector('.qty').value) * num(r.querySelector('.up').value)); r.querySelector('.amt').textContent = fmt(a); sub += a; });
        sub = r2(sub);
        var tp = document.getElementById('tp').value.trim(), tax = tp !== '' ? r2(sub * num(tp) / 100) : num(document.getElementById('tt').value);
        document.getElementById('tsub').textContent = fmt(sub); document.getElementById('ttax').textContent = fmt(tax); document.getElementById('ttot').textContent = fmt(r2(sub + tax));
    }
    function pick(row, keep) {
        var it = catalog[row.querySelector('.it').value], ac = row.querySelector('.ac'), note = row.querySelector('.inv'), rs = row.querySelector('.rs');
        var stock = it && it.type === 'inventory';
        rs.hidden = !stock; if (!stock) rs.querySelector('input').checked = false;
        if (!isAr && ac) { ac.hidden = !!stock; if (note) note.hidden = !stock; }
        if (it && !keep) {
            row.querySelector('.ds').value = it.description || it.name; row.querySelector('.up').value = it.price && parseFloat(it.price) ? it.price : '';
            if (!isAr && ac && !stock && it.ledger_id) ac.value = it.ledger_id;
            if (stock) rs.querySelector('input').checked = true;
        }
        totals();
    }
    function renum() { body.querySelectorAll('tr.cline').forEach(function (r, i) { r.querySelectorAll('[name^="lines["]').forEach(function (el) { el.name = el.name.replace(/^lines\[\d+\]/, 'lines[' + i + ']'); }); }); }
    function wire(row, keep) {
        row.querySelector('.it').addEventListener('change', function () { pick(row, false); });
        ['qty', 'up'].forEach(function (c) { row.querySelector('.' + c).addEventListener('input', totals); });
        row.querySelector('.rm').addEventListener('click', function () { if (body.querySelectorAll('tr.cline').length > 1) { row.remove(); renum(); totals(); } });
        pick(row, keep);
    }
    document.getElementById('addLine').addEventListener('click', function () {
        var row = body.firstElementChild.cloneNode(true);
        row.querySelectorAll('input[type=text],input:not([type]),input.num').forEach(function (i) { i.value = i.classList.contains('qty') ? '1' : ''; });
        row.querySelector('input[type=hidden]').value = '';
        row.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; s.hidden = false; });
        row.querySelector('input[type=checkbox]').checked = false;
        body.appendChild(row); renum(); wire(row, true);
    });
    ['tp', 'tt'].forEach(function (id) { document.getElementById(id).addEventListener('input', totals); });
    body.querySelectorAll('tr.cline').forEach(function (r) { wire(r, true); }); totals();
})();
</script>
@endsection
