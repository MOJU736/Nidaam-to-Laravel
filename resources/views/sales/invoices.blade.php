@extends('layouts.main')
@section('title', 'Invoices')
@section('content')
@php use App\Support\Money; @endphp
@include('sales._tabs')
<div class="bar">
    <h1 style="margin:0">Invoices</h1>
    <div class="actions"><input id="q" placeholder="Search..." style="width:200px"><a class="btn" href="{{ route('invoices.create') }}">+ New invoice</a></div>
</div>
@if ($draftsOnly)<p class="muted">Showing drafts only - <a href="{{ route('invoices.index') }}">show all</a></p>@endif
@if ($quotations->isNotEmpty() && ! $draftsOnly)
    <p class="muted" style="font-size:13px">Open quotations ready to invoice: @foreach ($quotations as $qt)<a href="{{ route('quotations.show', $qt->id) }}">{{ $sales->label('quotation', $qt->number) }} ({{ $qt->customer_name }})</a>@unless ($loop->last), @endunless @endforeach</p>
@endif
<div class="card tbl">
    <table id="list">
        <thead><tr><th>No.</th><th>Customer</th><th>Date</th><th>Due</th><th>Status</th><th class="num">Total</th><th class="num">Balance due</th></tr></thead>
        <tbody>
        @forelse ($rows as $i)
            @php $badge = ['paid' => ['Paid', ''], 'partial' => ['Partly paid', 'owes'], 'unpaid' => ['Unpaid', 'owes'], 'overdue' => ['Overdue', 'overdue']][$i->pay_status] ?? [ucfirst($i->status), $i->status === 'void' ? 'overdue' : '']; @endphp
            <tr data-text="{{ strtolower($i->customer_name.' '.$sales->label('invoice', $i->number)) }}">
                <td><a href="{{ route('invoices.show', $i->id) }}">{{ $sales->label('invoice', $i->number) }}</a></td>
                <td>{{ $i->customer_name }}</td><td>{{ Money::date($i->date) }}</td><td>{{ $i->due_date ? Money::date($i->due_date) : '—' }}</td>
                <td><span class="pill {{ $badge[1] }}">{{ $badge[0] }}</span></td>
                <td class="num">{{ Money::format($i->total) }}</td>
                <td class="num">{{ $i->balance_due !== null ? Money::format($i->balance_due) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted" style="text-align:center;padding:24px">No invoices yet. <a href="{{ route('invoices.create') }}">Create the first one</a>.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<script>
(function () { var q = document.getElementById('q'), rows = [].slice.call(document.querySelectorAll('#list tbody tr[data-text]'));
    q.addEventListener('input', function () { var t = q.value.trim().toLowerCase(); rows.forEach(function (r) { r.hidden = t && r.dataset.text.indexOf(t) === -1; }); }); })();
</script>
@endsection
