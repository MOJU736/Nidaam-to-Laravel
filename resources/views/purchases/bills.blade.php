@extends('layouts.main')
@section('title', 'Bills')
@section('content')
@php use App\Support\Money; @endphp
@include('purchases._tabs')
<div class="bar"><h1 style="margin:0">Bills</h1><div class="actions"><input id="q" placeholder="Search..." style="width:200px"><a class="btn" href="{{ route('bills.create') }}">+ New bill</a></div></div>
@if ($draftsOnly)<p class="muted">Showing drafts only - <a href="{{ route('bills.index') }}">show all</a></p>@endif
<div class="card tbl">
    <table id="list">
        <thead><tr><th>No.</th><th>Vendor</th><th>Reference</th><th>Date</th><th>Due</th><th>Status</th><th class="num">Total</th><th class="num">Balance due</th></tr></thead>
        <tbody>
        @forelse ($rows as $b)
            @php $badge = ['paid' => ['Paid', ''], 'partial' => ['Partly paid', 'owes'], 'unpaid' => ['Unpaid', 'owes'], 'overdue' => ['Overdue', 'overdue']][$b->pay_status] ?? [ucfirst($b->status), $b->status === 'void' ? 'overdue' : '']; @endphp
            <tr data-text="{{ strtolower($b->vendor_name.' '.$b->vendor_ref.' '.$purchases->billLabel($b->number)) }}">
                <td><a href="{{ route('bills.show', $b->id) }}">{{ $purchases->billLabel($b->number) }}</a></td>
                <td>{{ $b->vendor_name }}</td><td>{{ $b->vendor_ref ?: '—' }}</td><td>{{ Money::date($b->date) }}</td><td>{{ $b->due_date ? Money::date($b->due_date) : '—' }}</td>
                <td><span class="pill {{ $badge[1] }}">{{ $badge[0] }}</span></td><td class="num">{{ Money::format($b->total) }}</td><td class="num">{{ $b->balance_due !== null ? Money::format($b->balance_due) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted" style="text-align:center;padding:24px">No bills yet. <a href="{{ route('bills.create') }}">Create the first one</a>.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<script>(function () { var q = document.getElementById('q'), rows = [].slice.call(document.querySelectorAll('#list tbody tr[data-text]')); q.addEventListener('input', function () { var t = q.value.trim().toLowerCase(); rows.forEach(function (r) { r.hidden = t && r.dataset.text.indexOf(t) === -1; }); }); })();</script>
@endsection
