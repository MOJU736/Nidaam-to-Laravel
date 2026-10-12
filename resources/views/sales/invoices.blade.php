@extends('layouts.main')
@section('title', 'Invoices')
@section('content')
@php
    use App\Support\Money;
    use App\Support\Icon;
    $out = '0.00'; $late = '0.00'; $nOut = 0; $nLate = 0; $nDraft = 0;
    foreach ($rows as $i) {
        $st = $i->pay_status ?? $i->status;
        if (in_array($st, ['unpaid', 'partial', 'overdue'], true)) { $out = Money::add($out, (string) ($i->balance_due ?? 0)); $nOut++; }
        if ($st === 'overdue') { $late = Money::add($late, (string) ($i->balance_due ?? 0)); $nLate++; }
        if ($i->status === 'draft') { $nDraft++; }
    }
@endphp
@include('sales._tabs')
<div class="bar">
    <div><h1>Invoices</h1><div class="muted">{{ $draftsOnly ? 'Drafts only' : 'What you have billed, and what is still to come in' }}</div></div>
    <a class="btn" href="{{ route('invoices.create') }}">{{ Icon::svg('plus', 16) }}New invoice</a>
</div>

<div class="tiles">
    <div class="tile"><small>Waiting to be paid</small><b>{{ Money::format($out) }}</b><span>{{ $nOut }} invoice{{ $nOut === 1 ? '' : 's' }}</span></div>
    <div class="tile {{ $nLate ? 'bad' : '' }}"><small>Overdue</small><b>{{ Money::format($late) }}</b><span>{{ $nLate }} invoice{{ $nLate === 1 ? '' : 's' }} past due</span></div>
    <div class="tile"><small>Drafts</small><b>{{ $nDraft }}</b><span>not issued yet</span></div>
</div>

@if ($quotations->isNotEmpty() && ! $draftsOnly)
    <p class="muted" style="font-size:13px">Open quotations ready to invoice: @foreach ($quotations as $qt)<a href="{{ route('quotations.show', $qt->id) }}">{{ $sales->label('quotation', $qt->number) }} ({{ $qt->customer_name }})</a>@unless ($loop->last), @endunless @endforeach</p>
@endif

<div class="tools">
    <div class="chips" id="chips">
        <button type="button" class="on" data-f="">All</button>
        <button type="button" data-f="unpaid,partial">Unpaid</button>
        <button type="button" data-f="overdue">Overdue</button>
        <button type="button" data-f="paid">Paid</button>
        <button type="button" data-f="draft">Draft</button>
        <button type="button" data-f="void">Void</button>
    </div>
    <input id="q" class="find" type="search" placeholder="Search customer or number">
</div>

<div class="card tbl">
    <table id="list">
        <thead><tr><th>No.</th><th>Customer</th><th>Date</th><th>Due</th><th>Status</th><th class="num">Total</th><th class="num">Balance due</th></tr></thead>
        <tbody>
        @forelse ($rows as $i)
            @php $badge = ['paid' => ['Paid', ''], 'partial' => ['Partly paid', 'owes'], 'unpaid' => ['Unpaid', 'owes'], 'overdue' => ['Overdue', 'overdue']][$i->pay_status] ?? [ucfirst($i->status), $i->status === 'void' ? 'overdue' : '']; @endphp
            <tr data-st="{{ $i->pay_status ?? $i->status }}" data-text="{{ strtolower($i->customer_name.' '.$sales->label('invoice', $i->number)) }}">
                <td><a href="{{ route('invoices.show', $i->id) }}"><b>{{ $sales->label('invoice', $i->number) }}</b></a></td>
                <td>{{ $i->customer_name }}</td><td>{{ Money::date($i->date) }}</td><td>{{ $i->due_date ? Money::date($i->due_date) : '—' }}</td>
                <td><span class="pill {{ $badge[1] }}">{{ $badge[0] }}</span></td>
                <td class="num">{{ Money::format($i->total) }}</td>
                <td class="num">{{ $i->balance_due !== null ? Money::format($i->balance_due) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted" style="text-align:center;padding:36px">No invoices yet. <a href="{{ route('invoices.create') }}">Create the first one</a>.</td></tr>
        @endforelse
        <tr id="none" hidden><td colspan="7" class="muted" style="text-align:center;padding:28px">Nothing matches.</td></tr>
        </tbody>
    </table>
</div>
<script>
(function () {
    var q = document.getElementById('q'), rows = [].slice.call(document.querySelectorAll('#list tbody tr[data-text]')), chips = [].slice.call(document.querySelectorAll('#chips button')), f = [];
    function apply() {
        var t = q.value.trim().toLowerCase(), shown = 0;
        rows.forEach(function (r) { var ok = (!f.length || f.indexOf(r.dataset.st) > -1) && (!t || r.dataset.text.indexOf(t) > -1); r.hidden = !ok; if (ok) { shown++; } });
        document.getElementById('none').hidden = shown > 0 || !rows.length;
    }
    q.addEventListener('input', apply);
    chips.forEach(function (c) { c.addEventListener('click', function () { chips.forEach(function (x) { x.classList.remove('on'); }); c.classList.add('on'); f = c.dataset.f ? c.dataset.f.split(',') : []; apply(); }); });
})();
</script>
@endsection
