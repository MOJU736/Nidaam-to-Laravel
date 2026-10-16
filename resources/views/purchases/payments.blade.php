@extends('layouts.main')
@section('title', 'Vendor payments')
@section('content')
@php use App\Support\Money; use App\Services\Sales\Allocations; @endphp
@include('purchases._tabs')
@php
    $month = '0.00'; $unapp = '0.00'; $nUn = 0; $ym = date('Y-m');
    foreach ($rows as $pp) {
        if ($pp->status !== 'paid') { continue; }
        if (substr((string) $pp->date, 0, 7) === $ym) { $month = Money::add($month, (string) $pp->amount); }
        $l0 = Money::sub(Money::sub($pp->amount, $applied[$pp->id] ?? 0), $refunded[$pp->id] ?? 0);
        if (Money::positive($l0)) { $unapp = Money::add($unapp, $l0); $nUn++; }
    }
@endphp
<div class="bar"><div><h1>Payments to vendors</h1><div class="muted">Money you have paid out</div></div><a class="btn" href="{{ route('vendor-payments.create') }}">{{ \App\Support\Icon::svg('plus', 16) }}Pay vendor</a></div>
<div class="tiles">
    <div class="tile"><small>Paid this month</small><b>{{ Money::format($month) }}</b><span>{{ date('F Y') }}</span></div>
    <div class="tile"><small>Prepayments not applied</small><b>{{ Money::format($unapp) }}</b><span>{{ $nUn }} payment{{ $nUn === 1 ? '' : 's' }} still to match to a bill</span></div>
</div>
<div class="card tbl"><table>
    <thead><tr><th>No.</th><th>Vendor</th><th>Date</th><th>Method</th><th>Status</th><th class="num">Amount</th><th class="num">Unapplied</th></tr></thead>
    <tbody>
    @forelse ($rows as $p)
        @php $left = $p->status === 'paid' ? Money::sub(Money::sub($p->amount, $applied[$p->id] ?? 0), $refunded[$p->id] ?? 0) : null; @endphp
        <tr><td><a href="{{ route('vendor-payments.show', $p->id) }}">{{ Allocations::docLabel('', $p->number) }}</a></td><td>{{ $p->vendor_name }}</td><td>{{ Money::date($p->date) }}</td><td>{{ $p->method ?: '—' }}</td>
            <td><span class="pill {{ $p->status === 'void' ? 'overdue' : '' }}">{{ $p->status === 'void' ? 'Void' : 'Paid' }}</span></td><td class="num">{{ Money::format($p->amount) }}</td>
            <td class="num">{{ $left !== null && Money::positive($left) ? Money::format($left) : '—' }}</td></tr>
    @empty
        <tr><td colspan="7" class="muted" style="text-align:center;padding:24px">No payments yet.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
