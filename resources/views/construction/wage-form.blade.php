@extends('layouts.main')
@section('title', 'New wage sheet')
@section('content')
@include('construction._tabs')
@php use App\Support\Money; @endphp
<div class="bar"><h1 style="margin:0">New wage sheet #{{ $next }}</h1><a class="muted" href="{{ route('construction.wages') }}">&larr; Back</a></div>
<form class="card" method="POST" action="{{ route('construction.wages.store') }}">
    @csrf
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <div class="row2">
        <div><label style="margin-top:0">Period from</label><input type="date" name="date_from" required value="{{ old('date_from') }}"></div>
        <div><label style="margin-top:0">Period to</label><input type="date" name="date_to" required value="{{ old('date_to', now()->toDateString()) }}"></div>
    </div>
    <div class="row2">
        <div><label>Sheet date <span class="muted">(when it is posted)</span></label><input type="date" name="date" required value="{{ old('date', now()->toDateString()) }}"></div>
        <div><label>Paid from <span class="muted">(leave empty if not paid yet)</span></label><select name="paid_ledger_id"><option value="">Not paid yet (Wages Payable)</option>@foreach ($banks as $id => $n)<option value="{{ $id }}" @selected((string) old('paid_ledger_id') === (string) $id)>{{ $n }}</option>@endforeach</select></div>
    </div>
    <h2>Workers</h2>
    <div class="tbl"><table>
        <thead><tr><th>Worker</th><th>Project</th><th class="num">Days</th><th class="num">Rate</th><th class="num">Amount <span class="muted">(monthly)</span></th><th>Note</th></tr></thead>
        <tbody>
        @foreach ($workers as $i => $w)
            <tr>
                <td><input type="hidden" name="worker_id[{{ $i }}]" value="{{ $w->id }}">{{ $w->name }}<div class="muted" style="font-size:12px">{{ $w->pay_type === 'daily' ? 'daily' : 'monthly' }} <a href="#" class="split" title="Add another line for this worker (a second site)">+ another site</a></div></td>
                <td><select name="project_id[{{ $i }}]" style="min-width:160px"><option value="">None</option>@foreach ($projects as $p)<option value="{{ $p['id'] }}" @selected((int) old("project_id.$i", $w->default_project_id) === $p['id'])>{{ $p['label'] }}</option>@endforeach</select></td>
                <td class="num">@if ($w->pay_type === 'daily')<input name="days[{{ $i }}]" inputmode="decimal" style="width:70px;text-align:right" value="{{ old("days.$i") }}">@endif</td>
                <td class="num"><input name="rate[{{ $i }}]" inputmode="decimal" style="width:100px;text-align:right" value="{{ old("rate.$i", $w->rate) }}"></td>
                <td class="num">@if ($w->pay_type !== 'daily')<input name="amount[{{ $i }}]" inputmode="decimal" style="width:110px;text-align:right" value="{{ old("amount.$i", $w->rate) }}">@endif</td>
                <td><input name="note[{{ $i }}]" maxlength="255" value="{{ old("note.$i") }}"></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <p class="muted" style="font-size:13px">A daily worker with no days entered is left off. Amount = days × rate. Each line is a cost of its project (Labour). A worker who moved sites gets a line per site: use "+ another site".</p>
    <label>Notes</label><input name="notes" maxlength="500" value="{{ old('notes') }}">
    <div class="actions" style="margin-top:14px"><button>Post wage sheet</button></div>
</form>
<script>
(function () {
    var body = document.querySelector('tbody'), next = body.querySelectorAll('tr').length;
    body.addEventListener('click', function (e) {
        if (!e.target.classList.contains('split')) { return; }
        e.preventDefault();
        var tr = e.target.closest('tr'), c = tr.cloneNode(true), i = next++;
        c.querySelectorAll('[name]').forEach(function (el) { el.name = el.name.replace(/\[\d+\]/, '[' + i + ']'); if (el.type !== 'hidden' && el.tagName !== 'SELECT') { if (el.name.indexOf('rate') < 0 && el.name.indexOf('amount') < 0) { el.value = ''; } } });
        c.querySelectorAll('.split').forEach(function (a) { a.remove(); });
        tr.parentNode.insertBefore(c, tr.nextSibling);
    });
})();
</script>
@endsection
