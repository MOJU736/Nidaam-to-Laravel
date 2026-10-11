@extends('layouts.main')
@section('title', 'Payments')
@section('content')
@php use App\Support\Money; use App\Services\Sales\Allocations; @endphp
@include('sales._tabs')
<div class="bar"><h1 style="margin:0">Payments received</h1><a class="btn" href="{{ route('payments.create') }}">+ Receive payment</a></div>
<div class="card tbl">
    <table>
        <thead><tr><th>No.</th><th>Customer</th><th>Date</th><th>Method</th><th>Status</th><th class="num">Amount</th><th class="num">Unapplied</th></tr></thead>
        <tbody>
        @forelse ($rows as $p)
            @php $left = $p->status === 'received' ? Money::sub(Money::sub($p->amount, $applied[$p->id] ?? 0), $refunded[$p->id] ?? 0) : null; @endphp
            <tr>
                <td><a href="{{ route('payments.show', $p->id) }}">{{ Allocations::docLabel('', $p->number) }}</a></td>
                <td>{{ $p->customer_name }}</td><td>{{ Money::date($p->date) }}</td><td>{{ $p->method ?: '—' }}</td>
                <td><span class="pill {{ $p->status === 'void' ? 'overdue' : '' }}">{{ $p->status === 'void' ? 'Void' : 'Received' }}</span></td>
                <td class="num">{{ Money::format($p->amount) }}</td>
                <td class="num">{{ $left !== null && Money::positive($left) ? Money::format($left) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted" style="text-align:center;padding:24px">No payments yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
