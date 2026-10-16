@extends('layouts.main')
@section('title', 'Purchase orders')
@section('content')
@php use App\Support\Money; @endphp
@include('purchases._tabs')
<div class="bar"><div><h1>Purchase orders</h1><div class="muted">What you have ordered from vendors</div></div><a class="btn" href="{{ route('purchase-orders.create') }}">{{ \App\Support\Icon::svg('plus', 16) }}New purchase order</a></div>
<div class="card tbl"><table>
    <thead><tr><th>No.</th><th>Vendor</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead>
    <tbody>
    @forelse ($rows as $p)
        <tr><td><a href="{{ route('purchase-orders.show', $p->id) }}">{{ $purchases->poLabel($p->number) }}</a></td><td>{{ $p->vendor_name }}</td><td>{{ Money::date($p->date) }}</td>
            <td><span class="pill {{ $p->status === 'converted' ? 'owes' : '' }}">{{ ucfirst($p->status) }}</span></td><td class="num">{{ Money::format($p->total) }}</td></tr>
    @empty
        <tr><td colspan="5" class="muted" style="text-align:center;padding:24px">No purchase orders yet.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
