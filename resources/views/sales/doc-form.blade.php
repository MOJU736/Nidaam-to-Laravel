@extends('layouts.main')
@php
    use App\Support\Money;
    $isInv = $kind === 'invoice';
    $editing = (bool) $doc;
    $word = $isInv ? 'Invoice' : 'Quotation';
    $action = $editing ? route($isInv ? 'invoices.update' : 'quotations.update', $doc->id) : route($isInv ? 'invoices.store' : 'quotations.store');
    $oldLines = old('lines');
    $lines = is_array($oldLines) ? array_values($oldLines) : ($editing ? $doc->items->map(fn ($i) => ['item_id' => $i->item_id, 'description' => $i->description, 'qty' => rtrim(rtrim((string) $i->qty, '0'), '.'), 'unit_price' => $i->unit_price,
        'discount_type' => $i->discount_type, 'discount_value' => $i->discount_value])->all() : [['item_id' => '', 'description' => '', 'qty' => '1', 'unit_price' => '', 'discount_type' => '', 'discount_value' => '']]);
    $d = fn ($f, $default = '') => old($f, $doc->{$f} ?? $default);
    $selVehicles = old('vehicle_ids', $doc->vehicle_ids ?? []);
@endphp
@section('title', ($editing ? 'Edit ' : 'New ').$word)
@section('content')
@include('sales._tabs')
<div class="bar"><h1 style="margin:0">{{ $editing ? 'Edit '.$word.' '.$sales->label($kind, $doc->number) : 'New '.$word }}</h1><a class="muted" href="{{ route($isInv ? 'invoices.index' : 'quotations.index') }}">&larr; Back</a></div>

<form class="card" method="POST" action="{{ $action }}" id="docForm">
    @csrf
    @if ($editing) @method('PUT') @endif
    @foreach (['number', 'date', 'customer_id', 'project_id'] as $k)@if ($errors->has($k))<p class="error">{{ $errors->first($k) }}</p>@endif @endforeach

    <div class="row2" style="grid-template-columns:2fr 1fr 1fr 1fr">
        <div><label for="customer_id" style="margin-top:0">Customer *</label>
            <select id="customer_id" name="customer_id" required><option value="">Choose a customer...</option>@foreach ($customers as $c)<option value="{{ $c->id }}" @selected((string) old('customer_id', $selectedCustomer) === (string) $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <div><label for="number" style="margin-top:0">{{ $word }} no. *</label><input id="number" name="number" inputmode="numeric" required value="{{ $d('number', $nextNumber) }}"></div>
        <div><label for="date" style="margin-top:0">Date *</label><input id="date" type="date" name="date" required value="{{ old('date', isset($doc->date) ? substr($doc->date, 0, 10) : now()->format('Y-m-d')) }}"></div>
        <div>
            @if ($isInv)<label for="due_date" style="margin-top:0">Due date</label><input id="due_date" type="date" name="due_date" value="{{ old('due_date', isset($doc->due_date) ? substr((string) $doc->due_date, 0, 10) : '') }}">
            @else<label for="expiry_date" style="margin-top:0">Valid until</label><input id="expiry_date" type="date" name="expiry_date" value="{{ old('expiry_date', isset($doc->expiry_date) ? substr((string) $doc->expiry_date, 0, 10) : '') }}">@endif
        </div>
    </div>

    @if ($isInv && $projectOptions)
        <div style="margin-top:12px;max-width:420px"><label for="project_id" style="margin-top:0">Project <span class="muted" style="font-weight:400">(optional - counts as billed to the project)</span></label>
            <select id="project_id" name="project_id"><option value="" data-customer="">None</option>@foreach ($projectOptions as $po)<option value="{{ $po['id'] }}" data-customer="{{ $po['customer_id'] }}" @selected((string) old('project_id', $selectedProject) === (string) $po['id'])>{{ $po['label'] }}</option>@endforeach</select></div>
        <script>
        (function () {
            var cust = document.getElementById('customer_id'), sel = document.getElementById('project_id');
            function filter() { Array.prototype.forEach.call(sel.options, function (o) { var ok = o.value === '' || o.getAttribute('data-customer') === cust.value; o.hidden = !ok; o.disabled = !ok; if (!ok && o.selected) { sel.value = ''; } }); }
            cust.addEventListener('change', filter); filter();
        })();
        </script>
    @endif
    @if ($vehiclesOn)
        <div id="vehicleBox" style="margin-top:12px" hidden>
            <label style="margin-top:0">Vehicles <span class="muted" style="font-weight:400">(optional - prices follow the vehicle model)</span></label>
            <div id="vehicleList" class="actions"></div>
        </div>
    @endif

    <div class="tbl" style="margin-top:16px">
    <table id="lines">
        <thead><tr><th style="min-width:200px">Item *</th><th style="min-width:160px">Description</th><th class="num" style="width:90px">Qty</th><th class="num" style="width:120px">Unit price</th><th style="width:150px">Discount</th><th class="num" style="width:110px">Amount</th><th></th></tr></thead>
        <tbody>
        @foreach ($lines as $i => $l)
            @php $l = array_merge(['item_id' => '', 'description' => '', 'qty' => '1', 'unit_price' => '', 'discount_type' => '', 'discount_value' => ''], (array) $l); @endphp
            <tr class="line">
                <td><select name="lines[{{ $i }}][item_id]" class="it"><option value="">Choose an item...</option>@foreach ($catalog as $id => $c)<option value="{{ $id }}" @selected((string) $l['item_id'] === (string) $id)>{{ $c['name'] }}</option>@endforeach</select><div class="muted stk" style="font-size:12px"></div></td>
                <td><input name="lines[{{ $i }}][description]" class="ds" value="{{ $l['description'] }}" maxlength="255"></td>
                <td><input name="lines[{{ $i }}][qty]" class="qty num" inputmode="decimal" value="{{ $l['qty'] }}"></td>
                <td><input name="lines[{{ $i }}][unit_price]" class="up num" inputmode="decimal" value="{{ $l['unit_price'] }}"></td>
                <td><div class="actions" style="flex-wrap:nowrap"><select name="lines[{{ $i }}][discount_type]" class="dt" style="width:62px"><option value="">—</option><option value="percent" @selected($l['discount_type'] === 'percent')>%</option><option value="amount" @selected($l['discount_type'] === 'amount')>$</option></select>
                    <input name="lines[{{ $i }}][discount_value]" class="dv num" inputmode="decimal" value="{{ $l['discount_value'] }}" style="width:80px"></div></td>
                <td class="num amt">0.00</td>
                <td><button type="button" class="ghost sm rm" title="Remove line">&times;</button></td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="7"><button type="button" class="ghost sm" id="add">+ Add line</button></td></tr>
            <tr><td colspan="5" class="num">Subtotal (after line discounts)</td><td class="num" id="tsub">0.00</td><td></td></tr>
            <tr><td colspan="4" class="num" style="vertical-align:middle">Discount on the whole {{ strtolower($word) }}</td>
                <td><div class="actions" style="flex-wrap:nowrap"><select name="doc_discount_type" id="ddt" style="width:62px"><option value="">—</option><option value="percent" @selected($d('discount_type') === 'percent')>%</option><option value="amount" @selected($d('discount_type') === 'amount')>$</option></select>
                    <input name="doc_discount_value" id="ddv" class="num" inputmode="decimal" style="width:80px" value="{{ old('doc_discount_value', $doc->discount_value ?? '') }}"></div></td>
                <td class="num" id="tdisc">0.00</td><td></td></tr>
            <tr><td colspan="4" class="num" style="vertical-align:middle">Tax</td>
                <td><div class="actions" style="flex-wrap:nowrap"><input name="tax_percent" id="tp" class="num" inputmode="decimal" placeholder="%" style="width:70px" value="{{ old('tax_percent', isset($doc->tax_percent) ? rtrim(rtrim((string) $doc->tax_percent, '0'), '.') : '') }}">
                    @include('settings._tax-picker', ['side' => 'ar', 'isNew' => ! $editing])
                    <input name="tax_total" id="tt" class="num" inputmode="decimal" placeholder="or amount" style="width:100px" value="{{ old('tax_total', $doc->tax_total ?? '') }}"></div></td>
                <td class="num" id="ttax">0.00</td><td></td></tr>
            <tr><td colspan="5" class="num"><strong>Total</strong></td><td class="num"><strong id="ttot">0.00</strong></td><td></td></tr>
        </tfoot>
    </table>
    </div>

    <label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" maxlength="500">{{ $d('notes') }}</textarea>

    @if (! empty($customFields))
        <div class="row2" style="margin-top:8px">
            @foreach ($customFields as $i => [$cfLabel])
                <div><label for="cf{{ $i }}_value">{{ $cfLabel }}</label><input id="cf{{ $i }}_value" name="cf{{ $i }}_value" maxlength="255" value="{{ $d("cf{$i}_value") }}"></div>
            @endforeach
        </div>
    @endif

    <div class="actions" style="margin-top:20px">
        @if ($isInv)
            <button type="submit" name="then" value="issue">{{ $editing ? 'Save & issue' : 'Save & issue' }}</button>
            <button type="submit" class="ghost">Save as draft</button>
        @else
            <button type="submit">Save quotation</button>
        @endif
        <a class="btn ghost" href="{{ route($isInv ? 'invoices.index' : 'quotations.index') }}">Cancel</a>
    </div>
</form>

<script>
(function () {
    var catalog = @json($catalog), vehicles = @json($vehicles), sel = @json(array_map('strval', (array) $selVehicles));
    var body = document.querySelector('#lines tbody'), cust = document.getElementById('customer_id');
    function num(v) { v = String(v).replace(/[, ]/g, ''); return v === '' || isNaN(v) ? 0 : parseFloat(v); }
    function r2(x) { return Math.round((x + Number.EPSILON) * 100) / 100; }
    function fmt(x) { return x.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function disc(base, type, val) { var v = num(val); if (!type || v <= 0 || base <= 0) return 0; var d = type === 'percent' ? r2(base * Math.min(100, v) / 100) : r2(v); return Math.min(d, base); }

    function totals() {
        var sub = 0;
        body.querySelectorAll('tr.line').forEach(function (r) {
            var amt = r2(num(r.querySelector('.qty').value) * num(r.querySelector('.up').value)), d = disc(amt, r.querySelector('.dt').value, r.querySelector('.dv').value);
            r.querySelector('.amt').textContent = fmt(amt); sub += amt - d;
        });
        sub = r2(sub);
        var dd = disc(sub, document.getElementById('ddt').value, document.getElementById('ddv').value), taxable = r2(sub - dd);
        var tp = document.getElementById('tp').value.trim(), tax = tp !== '' ? r2(taxable * num(tp) / 100) : num(document.getElementById('tt').value);
        document.getElementById('tsub').textContent = fmt(sub); document.getElementById('tdisc').textContent = dd ? '-' + fmt(dd) : '0.00';
        document.getElementById('ttax').textContent = fmt(tax); document.getElementById('ttot').textContent = fmt(r2(taxable + tax));
    }
    // a vehicle's model can have its own price for an item; with several vehicles ticked the prices add up
    function priceFor(itemId) {
        var it = catalog[itemId]; if (!it) return '';
        var boxes = [].slice.call(document.querySelectorAll('#vehicleList input:checked'));
        if (!boxes.length) return it.price;
        var sum = 0; boxes.forEach(function (b) { var m = b.dataset.model; sum += parseFloat((m && it.vehicle_prices && it.vehicle_prices[m]) ? it.vehicle_prices[m] : it.price); });
        return String(r2(sum));
    }
    function pick(row, keep) {
        var id = row.querySelector('.it').value, it = catalog[id];
        row.querySelector('.stk').textContent = it && it.stock !== null ? 'In stock: ' + parseFloat(it.stock) : '';
        if (!it) return;
        if (!keep) { row.querySelector('.ds').value = it.description || it.name; row.querySelector('.up').value = priceFor(id); }
        totals();
    }
    function wire(row, keep) {
        row.querySelector('.it').addEventListener('change', function () { pick(row, false); });
        ['qty', 'up', 'dv'].forEach(function (c) { row.querySelector('.' + c).addEventListener('input', totals); });
        row.querySelector('.dt').addEventListener('change', totals);
        row.querySelector('.rm').addEventListener('click', function () { if (body.querySelectorAll('tr.line').length > 1) { row.remove(); renumber(); totals(); } });
        pick(row, true);
    }
    function renumber() { body.querySelectorAll('tr.line').forEach(function (r, i) { r.querySelectorAll('[name^="lines["]').forEach(function (el) { el.name = el.name.replace(/^lines\[\d+\]/, 'lines[' + i + ']'); }); }); }
    document.getElementById('add').addEventListener('click', function () {
        var row = body.lastElementChild.cloneNode(true);
        row.querySelectorAll('input').forEach(function (i) { i.value = i.classList.contains('qty') ? '1' : ''; }); row.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
        body.appendChild(row); renumber(); wire(row, true); totals(); row.querySelector('.it').focus();
    });

    // the customer's own vehicles
    function drawVehicles() {
        var box = document.getElementById('vehicleBox'); if (!box) return;
        var list = vehicles[cust.value] || [], el = document.getElementById('vehicleList');
        el.innerHTML = list.map(function (v) { return '<label style="font-weight:400;margin:0"><input type="checkbox" name="vehicle_ids[]" value="' + v.id + '" data-model="' + (v.model_id || '') + '"' + (sel.indexOf(String(v.id)) > -1 ? ' checked' : '') + '> ' + v.label.replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</label>'; }).join('');
        box.hidden = !list.length;
        el.querySelectorAll('input').forEach(function (b) { b.addEventListener('change', function () { sel = [].slice.call(el.querySelectorAll('input:checked')).map(function (x) { return x.value; }); body.querySelectorAll('tr.line').forEach(function (r) { if (r.querySelector('.it').value) r.querySelector('.up').value = priceFor(r.querySelector('.it').value); }); totals(); }); });
    }
    cust.addEventListener('change', function () { sel = []; drawVehicles(); });
    ['ddt', 'ddv', 'tp', 'tt'].forEach(function (id) { document.getElementById(id).addEventListener('input', totals); document.getElementById(id).addEventListener('change', totals); });
    drawVehicles(); body.querySelectorAll('tr.line').forEach(function (r) { wire(r, true); }); totals();
})();
</script>
@endsection
