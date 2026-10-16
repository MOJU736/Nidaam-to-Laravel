@extends('layouts.main')
@section('title', 'Bills')
@section('content')
@php
    use App\Support\Money;
    use App\Support\Icon;
    $out = '0.00'; $late = '0.00'; $nOut = 0; $nLate = 0; $nDraft = 0;
    foreach ($rows as $b) {
        $st = $b->pay_status ?? $b->status;
        if (in_array($st, ['unpaid', 'partial', 'overdue'], true)) { $out = Money::add($out, (string) ($b->balance_due ?? 0)); $nOut++; }
        if ($st === 'overdue') { $late = Money::add($late, (string) ($b->balance_due ?? 0)); $nLate++; }
        if ($b->status === 'draft') { $nDraft++; }
    }
@endphp
@include('purchases._tabs')
<div class="bar">
    <div><h1>Bills</h1><div class="muted">{{ $draftsOnly ? 'Drafts only' : 'What vendors have billed you' }}</div></div>
    <a class="btn" href="{{ route('bills.create') }}">{{ Icon::svg('plus', 16) }}New bill</a>
</div>

<div class="tiles">
    <div class="tile"><small>You owe</small><b>{{ Money::format($out) }}</b><span>{{ $nOut }} unpaid bill{{ $nOut === 1 ? '' : 's' }}</span></div>
    <div class="tile {{ $nLate ? 'bad' : '' }}"><small>Overdue</small><b>{{ Money::format($late) }}</b><span>{{ $nLate }} bill{{ $nLate === 1 ? '' : 's' }} past due</span></div>
    <div class="tile"><small>Drafts</small><b>{{ $nDraft }}</b><span>not issued yet</span></div>
</div>

<div class="tools">
    <div class="chips" id="chips">
        <button type="button" class="on" data-f="">All</button>
        <button type="button" data-f="unpaid,partial">Unpaid</button>
        <button type="button" data-f="overdue">Overdue</button>
        <button type="button" data-f="paid">Paid</button>
        <button type="button" data-f="draft">Draft</button>
        <button type="button" data-f="void">Void</button>
    </div>
    <input id="q" class="find" type="search" placeholder="Search vendor, reference or number">
</div>

<div class="card tbl">
    <table id="list">
        <thead><tr><th>No.</th><th>Vendor</th><th>Reference</th><th>Date</th><th>Due</th><th>Status</th><th class="num">Total</th><th class="num">Balance due</th></tr></thead>
        <tbody>
        @forelse ($rows as $b)
            @php $badge = ['paid' => ['Paid', ''], 'partial' => ['Partly paid', 'owes'], 'unpaid' => ['Unpaid', 'owes'], 'overdue' => ['Overdue', 'overdue']][$b->pay_status] ?? [ucfirst($b->status), $b->status === 'void' ? 'overdue' : '']; @endphp
            <tr data-st="{{ $b->pay_status ?? $b->status }}" data-text="{{ strtolower($b->vendor_name.' '.$b->vendor_ref.' '.$purchases->billLabel($b->number)) }}">
                <td><a href="{{ route('bills.show', $b->id) }}"><b>{{ $purchases->billLabel($b->number) }}</b></a></td>
                <td>{{ $b->vendor_name }}</td><td>{{ $b->vendor_ref ?: '—' }}</td><td>{{ Money::date($b->date) }}</td><td>{{ $b->due_date ? Money::date($b->due_date) : '—' }}</td>
                <td><span class="pill {{ $badge[1] }}">{{ $badge[0] }}</span></td><td class="num">{{ Money::format($b->total) }}</td><td class="num">{{ $b->balance_due !== null ? Money::format($b->balance_due) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted" style="text-align:center;padding:36px">No bills yet. <a href="{{ route('bills.create') }}">Create the first one</a>.</td></tr>
        @endforelse
        <tr id="none" hidden><td colspan="8" class="muted" style="text-align:center;padding:28px">Nothing matches.</td></tr>
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