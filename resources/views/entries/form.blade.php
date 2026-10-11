@extends('layouts.main')
@section('title', ($entry ? 'Edit ' : 'New ').$typeName)
@section('content')
@php
    use App\Support\Money;
    $editing = (bool) $entry;
@endphp
<div class="bar">
    <h1 style="margin:0">{{ $editing ? 'Edit '.$typeName.' '.$numberLabel : 'New '.$typeName.($type->label === 'journal' ? ' entry' : '') }}</h1>
    <a class="muted" href="{{ $editing ? route('entries.show', [$type->label, $entry->id]) : route('entries.index') }}">&larr; Back</a>
</div>

<form class="card" method="POST" id="je" action="{{ $editing ? route('entries.update', [$type->label, $entry->id]) : route('entries.store', $type->label) }}">
    @csrf
    @if ($editing) @method('PUT') @endif
    @foreach (['number', 'date'] as $k)@if ($errors->has($k))<p class="error">{{ $errors->first($k) }}</p>@endif @endforeach

    <div class="row2" style="grid-template-columns:1fr 1fr 1fr">
        <div><label for="date" style="margin-top:0">Date *</label><input id="date" type="date" name="date" required value="{{ old('date', $entry?->date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}"></div>
        <div><label for="number" style="margin-top:0">Number</label>
            <input id="number" name="number" inputmode="numeric" value="{{ old('number', $entry->number ?? '') }}" placeholder="{{ $nextNumber ? 'Next: '.$nextNumber : '' }}" @if ((int) $type->numbering === 2) required @endif></div>
        <div><label for="tag_id" style="margin-top:0">Tag</label>
            <select id="tag_id" name="tag_id"><option value="">No tag</option>@foreach ($tags as $t)<option value="{{ $t->id }}" @selected((string) old('tag_id', $entry->tag_id ?? '') === (string) $t->id)>{{ $t->title }}</option>@endforeach</select></div>
    </div>

    <div class="tbl" style="margin-top:16px">
    <table id="lines">
        <thead><tr><th style="min-width:230px">Account</th><th>Description</th><th class="num" style="width:130px">Debit</th><th class="num" style="width:130px">Credit</th>@if ($projects)<th style="min-width:150px">Project</th>@endif<th></th></tr></thead>
        <tbody>
        @foreach ($lines as $i => $l)
            <tr class="line">
                <td>
                    <select name="lines[{{ $i }}][account]" class="acct">
                        <option value="">Choose an account...</option>
                        @foreach ($accounts as $a)
                            <option value="{{ $a['v'] }}" @disabled($a['g']) @selected((string) $l['account'] === (string) $a['v'])>{{ str_repeat("\u{00A0}\u{00A0}\u{00A0}", $a['d']).$a['t'] }}</option>
                        @endforeach
                    </select>
                    <select name="lines[{{ $i }}][party]" class="party" style="margin-top:6px" hidden>
                        <option value="">Choose...</option>
                    </select>
                    <input type="hidden" class="party-init" value="{{ $l['party'] }}">
                </td>
                <td><input name="lines[{{ $i }}][narration]" value="{{ $l['narration'] }}" maxlength="500"></td>
                <td><input name="lines[{{ $i }}][debit]" class="dr num" inputmode="decimal" value="{{ $l['debit'] }}" placeholder="0.00"></td>
                <td><input name="lines[{{ $i }}][credit]" class="cr num" inputmode="decimal" value="{{ $l['credit'] }}" placeholder="0.00"></td>
                @if ($projects)
                    <td><select name="lines[{{ $i }}][project]"><option value="">None</option>
                        @foreach ($projects as $group => $opts)<optgroup label="{{ $group }}">@foreach ($opts as $v => $t)<option value="{{ $v }}" @selected((string) $l['project'] === (string) $v)>{{ $t }}</option>@endforeach</optgroup>@endforeach
                    </select></td>
                @endif
                <td><button type="button" class="ghost sm rm" title="Remove line">&times;</button></td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr><td><button type="button" class="ghost sm" id="add">+ Add line</button></td><td class="num"><strong>Total</strong></td>
                <td class="num"><strong id="tdr">0.00</strong></td><td class="num"><strong id="tcr">0.00</strong></td>@if ($projects)<td></td>@endif<td></td></tr>
            <tr><td colspan="{{ $projects ? 6 : 5 }}" id="diff" class="muted"></td></tr>
        </tfoot>
    </table>
    </div>

    <label for="notes">Notes</label>
    <textarea id="notes" name="notes" rows="2" maxlength="500">{{ old('notes', $entry->notes ?? '') }}</textarea>

    <div class="actions" style="margin-top:20px">
        <button type="submit">{{ $editing ? 'Save changes' : 'Save' }}</button>
        @unless ($editing)<button type="submit" name="save_and_new" value="1" class="ghost">Save &amp; new</button>@endunless
        <a class="btn ghost" href="{{ $editing ? route('entries.show', [$type->label, $entry->id]) : route('entries.index') }}">Cancel</a>
    </div>
</form>

<script>
(function () {
    var parties = @json($parties), body = document.querySelector('#lines tbody');
    var tdr = document.getElementById('tdr'), tcr = document.getElementById('tcr'), diff = document.getElementById('diff');
    function num(v) { v = String(v).replace(/[, ]/g, ''); return v === '' || isNaN(v) ? 0 : parseFloat(v); }
    function fmt(n) { return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    function totals() {
        var d = 0, c = 0;
        body.querySelectorAll('tr.line').forEach(function (r) { d += num(r.querySelector('.dr').value); c += num(r.querySelector('.cr').value); });
        tdr.textContent = fmt(d); tcr.textContent = fmt(c);
        var x = Math.round((d - c) * 100) / 100;
        diff.textContent = x === 0 ? (d > 0 ? 'Balanced.' : '') : 'Out of balance by ' + fmt(Math.abs(x)) + ' - add ' + (x > 0 ? 'a credit' : 'a debit') + ' of that amount.';
        diff.style.color = x === 0 ? '' : 'var(--danger)';
    }
    // an Accounts Receivable / Payable line asks which customer or vendor
    function syncParty(row, keep) {
        var acct = row.querySelector('.acct'), sel = row.querySelector('.party'), v = acct.value;
        if (v.indexOf('party:') === 0) {
            var p = parties[v.slice(6)], was = keep || sel.value;
            sel.innerHTML = '<option value="">Choose the ' + p.who + '...</option>' + p.list.map(function (x) { return '<option value="' + x[0] + '">' + x[1].replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</option>'; }).join('');
            sel.value = was; sel.hidden = false; sel.required = true;
        } else { sel.hidden = true; sel.required = false; sel.value = ''; }
    }
    function wire(row) {
        var dr = row.querySelector('.dr'), cr = row.querySelector('.cr');
        // a line is a debit or a credit, never both
        dr.addEventListener('input', function () { if (num(dr.value)) cr.value = ''; totals(); });
        cr.addEventListener('input', function () { if (num(cr.value)) dr.value = ''; totals(); });
        row.querySelector('.acct').addEventListener('change', function () { syncParty(row); });
        row.querySelector('.rm').addEventListener('click', function () { if (body.querySelectorAll('tr.line').length > 2) { row.remove(); renumber(); totals(); } });
        syncParty(row, row.querySelector('.party-init').value);
    }
    function renumber() {
        body.querySelectorAll('tr.line').forEach(function (r, i) {
            r.querySelectorAll('[name^="lines["]').forEach(function (el) { el.name = el.name.replace(/^lines\[\d+\]/, 'lines[' + i + ']'); });
        });
    }
    document.getElementById('add').addEventListener('click', function () {
        var last = body.querySelector('tr.line:last-child'), row = last.cloneNode(true);
        row.querySelectorAll('input:not(.party-init), textarea').forEach(function (el) { el.value = ''; });
        row.querySelector('.party-init').value = ''; row.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
        body.appendChild(row); renumber(); wire(row); totals(); row.querySelector('.acct').focus();
    });
    body.querySelectorAll('tr.line').forEach(wire); totals();
})();
</script>
@endsection
