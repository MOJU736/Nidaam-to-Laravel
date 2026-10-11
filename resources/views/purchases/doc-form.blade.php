@extends('layouts.main')
@php
    $isBill = $kind === 'bill';
    $editing = (bool) $doc;
    $word = $isBill ? 'Bill' : 'Purchase order';
    $route = $isBill ? 'bills' : 'purchase-orders';
    $label = $editing ? ($isBill ? $purchases->billLabel($doc->number) : $purchases->poLabel($doc->number)) : '';
    $blankItem = ['item_id' => '', 'description' => '', 'qty' => '1', 'unit_price' => '', 'ledger_id' => '', 'discount_type' => '', 'discount_value' => '', 'project' => ''];
    $oldItems = old('items');
    $itemLines = is_array($oldItems) ? array_values($oldItems) : ($editing ? $doc->items->whereNotNull('item_id')->map(fn ($i) => ['item_id' => $i->item_id, 'description' => $i->description, 'qty' => rtrim(rtrim((string) $i->qty, '0'), '.'),
        'unit_price' => $i->unit_price, 'ledger_id' => $i->ledger_id, 'discount_type' => $i->discount_type ?? '', 'discount_value' => $i->discount_value ?? '', 'project' => ($i->project_id ?? null) ? $i->project_id.':'.$i->cost_type : ''])->values()->all() : []);
    $oldAcc = old('accounts');
    $accLines = is_array($oldAcc) ? array_values($oldAcc) : ($editing ? $doc->items->whereNull('item_id')->map(fn ($i) => ['ledger_id' => $i->ledger_id, 'description' => $i->description, 'amount' => $i->amount, 'project' => ($i->project_id ?? null) ? $i->project_id.':'.$i->cost_type : ''])->values()->all() : []);
    if (! $itemLines) { $itemLines = [$blankItem]; }
    if (! $accLines) { $accLines = [['ledger_id' => '', 'description' => '', 'amount' => '', 'project' => '']]; }
    $projectOptions = $projectOptions ?? null;
    $projectSelect = function ($name, $selected) use ($projectOptions) {
        $h = '<select name="'.$name.'" style="min-width:150px"><option value="">None</option>';
        foreach ($projectOptions as $group => $opts) {
            $h .= '<optgroup label="'.e($group).'">';
            foreach ($opts as $v => $t) { $h .= '<option value="'.e($v).'"'.((string) $selected === (string) $v ? ' selected' : '').'>'.e($t).'</option>'; }
            $h .= '</optgroup>';
        }
        return new \Illuminate\Support\HtmlString($h.'</select>');
    };
    $d = fn ($f, $default = '') => old($f, $doc->{$f} ?? $default);
@endphp
@section('title', ($editing ? 'Edit ' : 'New ').$word)
@section('content')
@include('purchases._tabs')
<div class="bar"><h1 style="margin:0">{{ $editing ? 'Edit '.$word.' '.$label : 'New '.strtolower($word) }}</h1><a class="muted" href="{{ route($route.'.index') }}">&larr; Back</a></div>

<form class="card" method="POST" id="docForm" action="{{ $editing ? route($route.'.update', $doc->id) : route($route.'.store') }}">
    @csrf
    @if ($editing) @method('PUT') @endif
    @foreach (['number', 'date', 'vendor_id'] as $k)@if ($errors->has($k))<p class="error">{{ $errors->first($k) }}</p>@endif @endforeach

    <div class="row2" style="grid-template-columns:2fr 1fr 1fr 1fr">
        <div><label for="vendor_id" style="margin-top:0">Vendor *</label>
            <select id="vendor_id" name="vendor_id" required><option value="">Choose a vendor...</option>@foreach ($vendors as $v)<option value="{{ $v->id }}" @selected((string) old('vendor_id', $selectedVendor) === (string) $v->id)>{{ $v->name }}</option>@endforeach</select></div>
        <div><label for="number" style="margin-top:0">{{ $word }} no. *</label><input id="number" name="number" inputmode="numeric" required value="{{ $d('number', $nextNumber) }}"></div>
        <div><label for="date" style="margin-top:0">Date *</label><input id="date" type="date" name="date" required value="{{ old('date', isset($doc->date) ? substr($doc->date, 0, 10) : now()->format('Y-m-d')) }}"></div>
        <div><label for="due_date" style="margin-top:0">Due date</label><input id="due_date" type="date" name="due_date" value="{{ old('due_date', isset($doc->due_date) ? substr((string) $doc->due_date, 0, 10) : '') }}"></div>
    </div>
    @if ($isBill)
        <div class="row2" style="margin-top:8px"><div><label for="vendor_ref">Vendor's invoice / reference</label><input id="vendor_ref" name="vendor_ref" maxlength="100" value="{{ $d('vendor_ref') }}"></div>
            <div><label for="method">Payment method (expected)</label><select id="method" name="method"><option value="">—</option>@foreach ($methods as $m)<option value="{{ $m }}" @selected((string) $d('method') === (string) $m)>{{ $m }}</option>@endforeach</select></div></div>
    @endif

    <h2>Item details <span class="muted" style="font-weight:400;font-size:13px">(stock and catalog items; stock goes to Inventory Asset)</span></h2>
    <div class="tbl"><table id="items">
        <thead><tr><th style="min-width:190px">Item</th><th style="min-width:130px">Description</th><th style="min-width:170px">Account</th><th class="num" style="width:80px">Qty</th><th class="num" style="width:110px">Unit cost</th>@if ($isBill)<th style="width:140px">Discount</th>@endif @if ($projectOptions)<th style="min-width:150px">Project</th>@endif<th class="num" style="width:100px">Amount</th><th></th></tr></thead>
        <tbody>
        @foreach ($itemLines as $i => $l)
            @php $l = array_merge($blankItem, (array) $l); @endphp
            <tr class="iline">
                <td><select name="items[{{ $i }}][item_id]" class="it"><option value="">Choose an item...</option>@foreach ($catalog as $id => $c)<option value="{{ $id }}" @selected((string) $l['item_id'] === (string) $id)>{{ $c['name'] }}</option>@endforeach</select></td>
                <td><input name="items[{{ $i }}][description]" class="ds" value="{{ $l['description'] }}" maxlength="255"></td>
                <td><select name="items[{{ $i }}][ledger_id]" class="ac"><option value="">Choose...</option>@foreach ($accounts as $id => $n)<option value="{{ $id }}" @selected((string) $l['ledger_id'] === (string) $id)>{{ $n }}</option>@endforeach</select><div class="muted inv" style="font-size:12px" hidden>Posted to Inventory Asset</div></td>
                <td><input name="items[{{ $i }}][qty]" class="qty num" inputmode="decimal" value="{{ $l['qty'] }}"></td>
                <td><input name="items[{{ $i }}][unit_price]" class="up num" inputmode="decimal" value="{{ $l['unit_price'] }}"></td>
                @if ($isBill)<td><div class="actions" style="flex-wrap:nowrap"><select name="items[{{ $i }}][discount_type]" class="dt" style="width:62px"><option value="">—</option><option value="percent" @selected($l['discount_type'] === 'percent')>%</option><option value="amount" @selected($l['discount_type'] === 'amount')>$</option></select><input name="items[{{ $i }}][discount_value]" class="dv num" inputmode="decimal" value="{{ $l['discount_value'] }}" style="width:70px"></div></td>@endif
                @if ($projectOptions)<td>{!! $projectSelect("items[$i][project]", $l['project']) !!}</td>@endif
                <td class="num amt">0.00</td>
                <td><button type="button" class="ghost sm rm" title="Remove line">&times;</button></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <button type="button" class="ghost sm" id="addItem" style="margin-top:6px">+ Add item line</button>

    <h2>Account details <span class="muted" style="font-weight:400;font-size:13px">(a cost with no item, like rent - one amount)</span></h2>
    <div class="tbl"><table id="accts">
        <thead><tr><th style="min-width:220px">Account</th><th>Description</th><th class="num" style="width:130px">Amount</th>@if ($projectOptions)<th style="min-width:150px">Project</th>@endif<th></th></tr></thead>
        <tbody>
        @foreach ($accLines as $i => $l)
            @php $l = array_merge(['ledger_id' => '', 'description' => '', 'amount' => '', 'project' => ''], (array) $l); @endphp
            <tr class="aline">
                <td><select name="accounts[{{ $i }}][ledger_id]"><option value="">Choose an account...</option>@foreach ($accounts as $id => $n)<option value="{{ $id }}" @selected((string) $l['ledger_id'] === (string) $id)>{{ $n }}</option>@endforeach</select></td>
                <td><input name="accounts[{{ $i }}][description]" value="{{ $l['description'] }}" maxlength="255"></td>
                <td><input name="accounts[{{ $i }}][amount]" class="aamt num" inputmode="decimal" value="{{ $l['amount'] }}"></td>
                @if ($projectOptions)<td>{!! $projectSelect("accounts[$i][project]", $l['project']) !!}</td>@endif
                <td><button type="button" class="ghost sm rm" title="Remove line">&times;</button></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <button type="button" class="ghost sm" id="addAcct" style="margin-top:6px">+ Add account line</button>

    @if ($errors->has('lines'))<p class="error">{{ $errors->first('lines') }}</p>@endif

    <table style="margin-top:16px;max-width:520px;margin-left:auto">
        <tr><td class="num">Subtotal{{ $isBill ? ' (after line discounts)' : '' }}</td><td class="num" id="tsub" style="width:120px">0.00</td></tr>
        @if ($isBill)<tr><td class="num"><span style="margin-right:8px">Discount on the whole bill</span>
            <select name="doc_discount_type" id="ddt" style="width:62px;display:inline-block"><option value="">—</option><option value="percent" @selected($d('discount_type') === 'percent')>%</option><option value="amount" @selected($d('discount_type') === 'amount')>$</option></select>
            <input name="doc_discount_value" id="ddv" class="num" inputmode="decimal" style="width:80px;display:inline-block" value="{{ old('doc_discount_value', $doc->discount_value ?? '') }}"></td><td class="num" id="tdisc">0.00</td></tr>@endif
        <tr><td class="num"><span style="margin-right:8px">Tax</span><input name="tax_percent" id="tp" class="num" inputmode="decimal" placeholder="%" style="width:70px;display:inline-block" value="{{ old('tax_percent', isset($doc->tax_percent) ? rtrim(rtrim((string) $doc->tax_percent, '0'), '.') : '') }}">
            @include('settings._tax-picker', ['side' => 'ap', 'isNew' => ! $editing])
            <input name="tax_total" id="tt" class="num" inputmode="decimal" placeholder="or amount" style="width:100px;display:inline-block" value="{{ old('tax_total', $doc->tax_total ?? '') }}"></td><td class="num" id="ttax">0.00</td></tr>
        <tr><td class="num"><strong>Total</strong></td><td class="num"><strong id="ttot">0.00</strong></td></tr>
    </table>

    <label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" maxlength="500">{{ $d('notes') }}</textarea>

    <div class="actions" style="margin-top:20px">
        @if ($isBill)<button type="submit" name="then" value="issue">Save &amp; issue</button><button type="submit" class="ghost">Save as draft</button>
        @else<button type="submit">Save purchase order</button>@endif
        <a class="btn ghost" href="{{ route($route.'.index') }}">Cancel</a>
    </div>
</form>

<script>
(function () {
    var catalog = @json($catalog), isBill = {{ $isBill ? 'true' : 'false' }};
    var ib = document.querySelector('#items tbody'), ab = document.querySelector('#accts tbody');
    function num(v) { v = String(v).replace(/[, ]/g, ''); return v === '' || isNaN(v) ? 0 : parseFloat(v); }
    function r2(x) { return Math.round((x + Number.EPSILON) * 100) / 100; }
    function fmt(x) { return x.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function disc(base, type, val) { var v = num(val); if (!type || v <= 0 || base <= 0) return 0; var d = type === 'percent' ? r2(base * Math.min(100, v) / 100) : r2(v); return Math.min(d, base); }
    function totals() {
        var sub = 0;
        ib.querySelectorAll('tr.iline').forEach(function (r) {
            var amt = r2(num(r.querySelector('.qty').value) * num(r.querySelector('.up').value)), d = isBill ? disc(amt, r.querySelector('.dt').value, r.querySelector('.dv').value) : 0;
            r.querySelector('.amt').textContent = fmt(amt); sub += amt - d;
        });
        ab.querySelectorAll('tr.aline').forEach(function (r) { sub += r2(num(r.querySelector('.aamt').value)); });
        sub = r2(sub);
        var dd = isBill ? disc(sub, document.getElementById('ddt').value, document.getElementById('ddv').value) : 0, taxable = r2(sub - dd);
        var tp = document.getElementById('tp').value.trim(), tax = tp !== '' ? r2(taxable * num(tp) / 100) : num(document.getElementById('tt').value);
        document.getElementById('tsub').textContent = fmt(sub); if (isBill) document.getElementById('tdisc').textContent = dd ? '-' + fmt(dd) : '0.00';
        document.getElementById('ttax').textContent = fmt(tax); document.getElementById('ttot').textContent = fmt(r2(taxable + tax));
    }
    function pick(row, keep) {
        var it = catalog[row.querySelector('.it').value], ac = row.querySelector('.ac'), note = row.querySelector('.inv');
        if (!it) { note.hidden = true; return; }
        if (!keep) { row.querySelector('.ds').value = it.description || it.name; row.querySelector('.up').value = it.cost && parseFloat(it.cost) ? it.cost : ''; }
        if (it.type === 'inventory') { ac.hidden = true; note.hidden = false; } else { ac.hidden = false; note.hidden = true; if (!keep && it.ledger_id) ac.value = it.ledger_id; }
        totals();
    }
    function renum(body, name) { body.querySelectorAll('tr').forEach(function (r, i) { r.querySelectorAll('[name^="' + name + '["]').forEach(function (el) { el.name = el.name.replace(new RegExp('^' + name + '\\[\\d+\\]'), name + '[' + i + ']'); }); }); }
    function wireItem(row, keep) {
        row.querySelector('.it').addEventListener('change', function () { pick(row, false); });
        ['qty', 'up'].forEach(function (c) { row.querySelector('.' + c).addEventListener('input', totals); });
        if (isBill) { row.querySelector('.dv').addEventListener('input', totals); row.querySelector('.dt').addEventListener('change', totals); }
        row.querySelector('.rm').addEventListener('click', function () { row.remove(); renum(ib, 'items'); totals(); });
        pick(row, keep);
    }
    function wireAcct(row) { row.querySelector('.aamt').addEventListener('input', totals); row.querySelector('.rm').addEventListener('click', function () { row.remove(); renum(ab, 'accounts'); totals(); }); }
    function clone(src, body) { var row = src.cloneNode(true); row.querySelectorAll('input').forEach(function (i) { i.value = i.classList.contains('qty') ? '1' : ''; }); row.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; s.hidden = false; }); var n = row.querySelector('.inv'); if (n) n.hidden = true; body.appendChild(row); return row; }
    var iTpl = ib.firstElementChild ? ib.firstElementChild.cloneNode(true) : null, aTpl = ab.firstElementChild ? ab.firstElementChild.cloneNode(true) : null;
    document.getElementById('addItem').addEventListener('click', function () { var r = clone(ib.firstElementChild || iTpl, ib); renum(ib, 'items'); wireItem(r, true); totals(); });
    document.getElementById('addAcct').addEventListener('click', function () {
        var r = clone(ab.firstElementChild, ab); renum(ab, 'accounts'); wireAcct(r); totals(); });
    ['tp', 'tt', 'ddt', 'ddv'].forEach(function (id) { var e = document.getElementById(id); if (e) { e.addEventListener('input', totals); e.addEventListener('change', totals); } });
    ib.querySelectorAll('tr.iline').forEach(function (r) { wireItem(r, true); }); ab.querySelectorAll('tr.aline').forEach(wireAcct); totals();
})();
</script>
@endsection
