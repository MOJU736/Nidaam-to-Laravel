@extends('layouts.main')
@section('title', 'Quotations')
@section('content')
@php use App\Support\Money; @endphp
@include('sales._tabs')
<div class="bar"><h1 style="margin:0">Quotations</h1><a class="btn" href="{{ route('quotations.create') }}">+ New quotation</a></div>
@if ($draftsOnly)<p class="muted">Showing drafts only - <a href="{{ route('quotations.index') }}">show all</a></p>@endif
<div class="card tbl">
    <table>
        <thead><tr><th>No.</th><th>Customer</th><th>Date</th><th>Valid until</th><th>Status</th><th class="num">Total</th></tr></thead>
        <tbody>
        @forelse ($rows as $q)
            <tr>
                <td><a href="{{ route('quotations.show', $q->id) }}">{{ $sales->label('quotation', $q->number) }}</a></td>
                <td>{{ $q->customer_name }}</td><td>{{ Money::date($q->date) }}</td><td>{{ $q->expiry_date ? Money::date($q->expiry_date) : '—' }}</td>
                <td><span class="pill {{ $q->status === 'converted' ? 'owes' : '' }}">{{ ucfirst($q->status) }}</span></td>
                <td class="num">{{ Money::format($q->total) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted" style="text-align:center;padding:24px">No quotations yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
