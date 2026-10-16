@extends('layouts.main')
@section('title', 'Payments')
@section('content')
@php
    use App\Support\Money; use App\Services\Sales\Allocations; use App\Support\Icon;
    $month = '0.00'; $unapp = '0.00'; $nUn = 0; $ym = date('Y-m');
    foreach ($rows as $p) {
        if ($p->status !== 'received') { continue; }
        if (substr((string) $p->date, 0, 7) === $ym) { $month = Money::add($month, (string) $p->amount); }
        $l = Money::sub(Money::sub($p->amount, $applied[$p->id] ?? 0), $refunded[$p->id] ?? 0);
        if (Money::positive($l)) { $unapp = Money::add($unapp, $l); $nUn++; }
    }
@endphp
@include('sales._tabs')
<div class="bar"><div><h1>Payments received</h1><div class="muted">Money customers have paid you</div></div><a class="btn" href="{{ route('payments.create') }}">{{ Icon::svg('plus', 16) }}Receive payment</a></div>
<div class="tiles">
    <div class="tile"><small>Received this month</small><b>{{ Money::format($month) }}</b><span>{{ date('F Y') }}</span></div>
    <div class="tile"><small>Not yet applied</small><b>{{ Money::format($unapp) }}</b><span>{{ $nUn }} payment{{ $nUn === 1 ? '' : 's' }} kept as customer credit</span></div>
</div>
<div class="tools"><div></div><input id="q" class="find" type="search" placeholder="Search customer or number"></div>
<div class="card tbl">
    <table id="list">
        <thead><tr><th>No.</th><th>Customer</th><th>Date</th><th>Method</th><th>Status</th><th class="num">Amount</th><th class="num">Unapplied</th></tr></thead>
        <tbody>
        @forelse ($rows as $p)
            @php $left = $p->status === 'received' ? Money::sub(Money::sub($p->amount, $applied[$p->id] ?? 0), $refunded[$p->id] ?? 0) : null; @endphp
            <tr data-text="{{ strtolower($p->customer_name.' '.Allocations::docLabel('', $p->number)) }}">
                <td><a href="{{ route('payments.show', $p->id) }}"><b>{{ Allocations::docLabel('', $p->number) }}</b></a></td>
                <td>{{ $p->customer_name }}</td><td>{{ Money::date($p->date) }}</td><td>{{ $p->method ?: '—' }}</td>
                <td><span class="pill {{ $p->status === 'void' ? 'overdue' : '' }}">{{ $p->status === 'void' ? 'Void' : 'Received' }}</span></td>
                <td class="num">{{ Money::format($p->amount) }}</td>
                <td class="num">{{ $left !== null && Money::positive($left) ? Money::format($left) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted" style="text-align:center;padding:36px">No payments yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<script>(function () { var q = document.getElementById('q'), rows = [].slice.call(document.querySelectorAll('#list tbody tr[data-text]')); q.addEventListener('input', function () { var t = q.value.trim().toLowerCase(); rows.forEach(function (r) { r.hidden = t && r.dataset.text.indexOf(t) === -1; }); }); })();</script>
@endsection