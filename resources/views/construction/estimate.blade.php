@extends('layouts.main')
@section('title', 'Estimate - '.$p->name)
@section('content')
@include('construction._tabs')
@php
    use App\Support\Money; use App\Services\Construction\ConstructionService as C;
    $draft = $e && $e->status === 'draft';
    $pctv = fn ($x) => rtrim(rtrim((string) $x, '0'), '.') ?: '0';
@endphp
@include('construction._ptabs', ['p' => $p, 'on' => 'estimate'])
@foreach ($errors->all() as $er)<div class="flash bad">{{ $er }}</div>@endforeach

@if (! $e)
    <div class="card" style="max-width:640px">
        <h2 style="margin-top:0">No estimate yet</h2>
        <p class="muted">An estimate is a bill of quantities: sections of work with quantity × rate. Approving it sets the project's contract and its budgets.</p>
        <form method="POST" action="{{ route('projects.estimate.create', $p->id) }}">@csrf
            <div class="actions" style="align-items:end"><button>Start a new estimate</button>
            @if ($copyFrom)<span class="muted">or copy</span><select name="from_id" style="max-width:340px"><option value="">an empty estimate</option>@foreach ($copyFrom as $id => $l)<option value="{{ $id }}">{{ $l }}</option>@endforeach</select>@endif</div>
        </form>
    </div>
@else
    <div class="bar">
        <div><h1 style="margin:0">Estimate {{ $e->number }} <span class="pill">{{ ['draft' => 'Draft', 'approved' => 'Approved', 'replaced' => 'Replaced'][$e->status] ?? $e->status }}</span></h1>
            @if ($e->approved_at)<div class="muted">Approved {{ Money::date($e->approved_at) }}{{ $e->approved_by ? ' by '.$e->approved_by : '' }} · the contract and budgets were set from it</div>@endif</div>
        <div class="actions">
            @foreach ($history as $h)@if ($h->id !== $e->id)<a class="btn sm ghost" href="{{ route('projects.estimate', $p->id) }}?e={{ $h->id }}">Estimate {{ $h->number }} ({{ $h->status }})</a>@endif @endforeach
            <a class="btn sm ghost" href="{{ route('projects.estimate.pdf', $e->id) }}" target="_blank">PDF</a>
            @if ($e->status === 'approved')<form class="inline" method="POST" action="{{ route('projects.estimate.revise', $e->id) }}" onsubmit="return confirm('Start a revision? It is a new draft copied from this estimate.')">@csrf<button class="ghost sm">Revise</button></form>@endif
        </div>
    </div>

    @if ($draft)
    <form id="estForm" method="POST" action="{{ route('projects.estimate.save', $e->id) }}">
        @csrf @method('PUT')
        <input type="hidden" name="sections_json" id="sectionsJson">
        <div class="card" style="margin-bottom:14px">
            <div class="row2" style="grid-template-columns:2fr 1fr 1fr 1fr">
                <div><label style="margin-top:0">Title</label><input name="title" maxlength="255" value="{{ old('title', $e->title) }}"></div>
                <div><label style="margin-top:0">Date</label><input type="date" name="date" required value="{{ old('date', substr((string) $e->date, 0, 10)) }}"></div>
                <div><label style="margin-top:0">Tax %</label><input name="tax_percent" id="taxp" inputmode="decimal" value="{{ old('tax_percent', $pctv($e->tax_percent)) }}">
                    @if ($taxes->isNotEmpty())<select id="taxPick" style="margin-top:4px"><option value="">Pick a rate...</option>@foreach ($taxes as $t)<option value="{{ \App\Services\Settings\SettingsService::pct($t->rate) }}" data-name="{{ $t->name }}">{{ $t->name }} ({{ \App\Services\Settings\SettingsService::pct($t->rate) }}%)</option>@endforeach</select>@endif</div>
                <div><label style="margin-top:0">Tax name</label><input name="tax_name" id="taxn" maxlength="100" value="{{ old('tax_name', $e->tax_name) }}"></div>
            </div>
            <div class="row2"><div><label>Expected margin % <span class="muted">(budgets are set at the estimate less this margin)</span></label><input name="margin_percent" inputmode="decimal" value="{{ old('margin_percent', $pctv($e->margin_percent)) }}" style="max-width:140px"></div>
                <div><label>Notes <span class="muted">(shown on the PDF)</span></label><input name="notes" maxlength="2000" value="{{ old('notes', $e->notes) }}"></div></div>
        </div>
        <div id="sections"></div>
        <div class="actions" style="margin:8px 0 14px"><button type="button" class="ghost sm" id="addSection">+ Add section</button>
            <label style="font-weight:400;margin:0 0 0 12px"><input type="checkbox" name="save_rates" value="1"> Add new descriptions to the rate list when saving</label></div>
        <datalist id="rateList">@foreach ($rates as $r)<option value="{{ $r->description }}"></option>@endforeach</datalist>
        <table style="max-width:420px;margin-left:auto"><tr><td class="num">Total before tax</td><td class="num" id="gSub" style="width:150px">0.00</td></tr><tr><td class="num">Tax</td><td class="num" id="gTax">0.00</td></tr><tr><td class="num"><strong>Total</strong></td><td class="num"><strong id="gTot">0.00</strong></td></tr></table>
        <div class="actions" style="margin-top:14px"><button name="then" value="save">Save estimate</button>
            @if ($canApprove)<button name="then" value="approve" class="ghost" onclick="return confirm('Approve this estimate? The project contract becomes its total before tax and the budgets are set from it.')">Save &amp; approve</button>@else<span class="muted">An administrator or manager approves it.</span>@endif</div>
    </form>
    <form method="POST" action="{{ route('projects.estimate.destroy', $e->id) }}" style="margin-top:12px" onsubmit="return confirm('Delete this draft estimate?')">@csrf @method('DELETE')<button class="danger sm">Delete draft</button></form>
    @else
    <div class="card tbl" style="margin-bottom:14px">
        <div class="muted" style="margin-bottom:6px">{{ $e->title }} · {{ Money::date($e->date) }}</div>
        <table>
            <thead><tr><th>Description</th><th>Unit</th><th>Cost type</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead>
            <tbody>
            @foreach ($e->sections as $s)@continue ($s->items->isEmpty())
                <tr style="background:var(--bg)"><td colspan="5"><strong>{{ $s->name }}</strong></td><td class="num"><strong>{{ Money::format($s->total) }}</strong></td></tr>
                @foreach ($s->items as $i)<tr><td>{{ $i->description }}</td><td>{{ $i->unit }}</td><td>{{ C::COST_TYPES[$i->cost_type] ?? $i->cost_type }}</td><td class="num">{{ $pctv($i->qty) }}</td><td class="num">{{ Money::format($i->rate) }}</td><td class="num">{{ Money::format($i->amount) }}</td></tr>@endforeach
            @endforeach
            </tbody>
            <tfoot><tr><th colspan="5" class="num">Total before tax</th><th class="num">{{ Money::format($e->subtotal) }}</th></tr>
                @if (Money::positive($e->tax_total))<tr><td colspan="5" class="num">{{ $e->tax_name ?: 'Tax' }} {{ $pctv($e->tax_percent) }}%</td><td class="num">{{ Money::format($e->tax_total) }}</td></tr><tr><th colspan="5" class="num">Total</th><th class="num">{{ Money::format($e->total) }}</th></tr>@endif</tfoot>
        </table>
        @if ($e->notes)<p class="muted">{{ $e->notes }}</p>@endif
    </div>
    @endif

    <form class="card" method="POST" action="{{ route('projects.estimate.email', $e->id) }}" style="max-width:640px;margin-top:16px">
        @csrf <h2 style="margin-top:0">Email the estimate</h2>
        <div class="actions" style="align-items:end"><div style="flex:1"><label style="margin-top:0">To</label><input name="email" value="{{ $customerEmail }}" placeholder="client@example.com"></div><button class="ghost" onclick="return confirm('Send the estimate PDF by email now?')">Send</button></div>
    </form>
@endif

@if ($draft)
<script>
(function () {
    var data = @json($sections), units = @json($units), types = @json(C::COST_TYPES), rates = @json($rates->mapWithKeys(fn ($r) => [$r->description => ['unit' => $r->unit, 'rate' => $r->rate, 'cost_type' => $r->cost_type]])),
        host = document.getElementById('sections');
    if (!data.length) { data = [{ name: '', items: [{}] }]; }
    function num(v) { v = parseFloat(String(v === undefined ? '' : v).replace(/,/g, '')); return isNaN(v) ? 0 : v; }
    function money(v) { return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function opts(list, sel, isObj) { var h = ''; (isObj ? Object.keys(list) : list).forEach(function (k) { var label = isObj ? list[k] : k; h += '<option value="' + k + '"' + (k === sel ? ' selected' : '') + '>' + label + '</option>'; }); return h; }
    function esc(s) { return String(s === undefined || s === null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
    function draw() {
        host.innerHTML = '';
        data.forEach(function (s, si) {
            var card = document.createElement('div'); card.className = 'card tbl'; card.style.marginBottom = '12px';
            var h = '<div class="actions" style="margin-bottom:6px"><input class="sname" data-s="' + si + '" placeholder="Section name" value="' + esc(s.name) + '" style="max-width:320px;font-weight:700"> <span class="muted stot" data-s="' + si + '"></span><span class="sp" style="flex:1"></span><button type="button" class="danger sm rs" data-s="' + si + '">Remove section</button></div>';
            h += '<table><thead><tr><th style="min-width:240px">Description</th><th style="width:90px">Unit</th><th style="width:140px">Cost type</th><th class="num" style="width:90px">Qty</th><th class="num" style="width:110px">Rate</th><th class="num" style="width:110px">Amount</th><th></th></tr></thead><tbody>';
            s.items.forEach(function (it, ii) {
                h += '<tr><td><input class="f" data-s="' + si + '" data-i="' + ii + '" data-k="description" list="rateList" value="' + esc(it.description) + '"></td>'
                  + '<td><select class="f" data-s="' + si + '" data-i="' + ii + '" data-k="unit"><option value=""></option>' + opts(units, it.unit || '', false) + '</select></td>'
                  + '<td><select class="f" data-s="' + si + '" data-i="' + ii + '" data-k="cost_type">' + opts(types, it.cost_type || 'materials', true) + '</select></td>'
                  + '<td><input class="f num" inputmode="decimal" data-s="' + si + '" data-i="' + ii + '" data-k="qty" value="' + esc(it.qty) + '"></td>'
                  + '<td><input class="f num" inputmode="decimal" data-s="' + si + '" data-i="' + ii + '" data-k="rate" value="' + esc(it.rate) + '"></td>'
                  + '<td class="num amt" data-s="' + si + '" data-i="' + ii + '"></td><td><button type="button" class="ghost sm ri" data-s="' + si + '" data-i="' + ii + '" title="Remove line">&times;</button></td></tr>';
            });
            h += '</tbody></table><button type="button" class="ghost sm ai" data-s="' + si + '" style="margin-top:6px">+ Add line</button>';
            card.innerHTML = h; host.appendChild(card);
        });
        totals();
    }
    function totals() {
        var sub = 0;
        data.forEach(function (s, si) {
            var st = 0;
            s.items.forEach(function (it, ii) { var a = Math.round(num(it.qty) * num(it.rate) * 100) / 100; st += a; var c = host.querySelector('.amt[data-s="' + si + '"][data-i="' + ii + '"]'); if (c) { c.textContent = a ? money(a) : ''; } });
            sub += st; var l = host.querySelector('.stot[data-s="' + si + '"]'); if (l) { l.textContent = money(st); }
        });
        var tax = Math.round(sub * num(document.getElementById('taxp').value) / 100 * 100) / 100;
        document.getElementById('gSub').textContent = money(sub); document.getElementById('gTax').textContent = money(tax); document.getElementById('gTot').textContent = money(sub + tax);
    }
    host.addEventListener('input', function (e) {
        var t = e.target, s = t.getAttribute('data-s'); if (s === null) { return; }
        if (t.classList.contains('sname')) { data[s].name = t.value; return; }
        var it = data[s].items[t.getAttribute('data-i')], k = t.getAttribute('data-k'); it[k] = t.value;
        if (k === 'description' && rates[t.value]) { var r = rates[t.value]; it.unit = r.unit; it.rate = r.rate; it.cost_type = r.cost_type; draw(); return; }
        totals();
    });
    host.addEventListener('change', function (e) { if (e.target.tagName === 'SELECT' && e.target.getAttribute('data-k')) { data[e.target.getAttribute('data-s')].items[e.target.getAttribute('data-i')][e.target.getAttribute('data-k')] = e.target.value; } });
    host.addEventListener('click', function (e) {
        var c = e.target.classList, s = e.target.getAttribute('data-s'), i = e.target.getAttribute('data-i');
        if (c.contains('ai')) { data[s].items.push({}); draw(); } else if (c.contains('ri')) { data[s].items.splice(i, 1); if (!data[s].items.length) { data[s].items.push({}); } draw(); }
        else if (c.contains('rs')) { if (confirm('Remove this section and its lines?')) { data.splice(s, 1); draw(); } }
    });
    document.getElementById('addSection').addEventListener('click', function () { data.push({ name: '', items: [{}] }); draw(); });
    document.getElementById('taxp').addEventListener('input', totals);
    var pick = document.getElementById('taxPick'); if (pick) { pick.addEventListener('change', function () { if (pick.value !== '') { document.getElementById('taxp').value = pick.value; document.getElementById('taxn').value = pick.options[pick.selectedIndex].getAttribute('data-name'); totals(); } }); }
    document.getElementById('estForm').addEventListener('submit', function () { document.getElementById('sectionsJson').value = JSON.stringify(data); });
    draw();
})();
</script>
@endif
@endsection
