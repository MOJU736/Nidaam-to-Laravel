@extends('layouts.main')
@section('title', 'Quotations')
@section('content')
@php
    use App\Support\Money;
    use App\Support\Icon;
    $open = '0.00'; $nOpen = 0; $nConv = 0; $nDraft = 0;
    foreach ($rows as $q) {
        if ($q->status === 'converted') { $nConv++; }
        elseif ($q->status === 'draft') { $nDraft++; $open = Money::add($open, (string) $q->total); $nOpen++; }
        elseif ($q->status !== 'void') { $open = Money::add($open, (string) $q->total); $nOpen++; }
    }
@endphp
@include('sales._tabs')
<div class="bar">
    <div><h1>Quotations</h1><div class="muted">{{ $draftsOnly ? 'Drafts only' : 'Offers you have sent, and which became invoices' }}@if ($draftsOnly) · <a href="{{ route('quotations.index') }}">show all</a>@endif</div></div>
    <a class="btn" href="{{ route('quotations.create') }}">{{ Icon::svg('plus', 16) }}New quotation</a>
</div>
<div class="tiles">
    <div class="tile"><small>Still open</small><b>{{ Money::format($open) }}</b><span>{{ $nOpen }} quotation{{ $nOpen === 1 ? '' : 's' }} not yet invoiced</span></div>
    <div class="tile"><small>Became invoices</small><b>{{ $nConv }}</b><span>converted</span></div>
    <div class="tile"><small>Drafts</small><b>{{ $nDraft }}</b><span>not sent yet</span></div>
</div>
<div class="tools"><div></div><input id="q" class="find" type="search" placeholder="Search customer or number"></div>
<div class="card tbl">
    <table id="list">
        <thead><tr><th>No.</th><th>Customer</th><th>Date</th><th>Valid until</th><th>Status</th><th class="num">Total</th></tr></thead>
        <tbody>
        @forelse ($rows as $q)
            <tr data-text="{{ strtolower($q->customer_name.' '.$sales->label('quotation', $q->number)) }}">
                <td><a href="{{ route('quotations.show', $q->id) }}"><b>{{ $sales->label('quotation', $q->number) }}</b></a></td>
                <td>{{ $q->customer_name }}</td><td>{{ Money::date($q->date) }}</td><td>{{ $q->expiry_date ? Money::date($q->expiry_date) : '—' }}</td>
                <td><span class="pill {{ $q->status === 'converted' ? 'owes' : '' }}">{{ ucfirst($q->status) }}</span></td>
                <td class="num">{{ Money::format($q->total) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted" style="text-align:center;padding:36px">No quotations yet. <a href="{{ route('quotations.create') }}">Write the first one</a>.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<script>(function () { var q = document.getElementById('q'), rows = [].slice.call(document.querySelectorAll('#list tbody tr[data-text]')); q.addEventListener('input', function () { var t = q.value.trim().toLowerCase(); rows.forEach(function (r) { r.hidden = t && r.dataset.text.indexOf(t) === -1; }); }); })();</script>
@endsection