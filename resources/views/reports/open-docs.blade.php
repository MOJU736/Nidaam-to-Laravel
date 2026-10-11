@extends('layouts.main')
@section('title', $title)
@section('content')
@php use App\Support\Money; $ar = $side === 'ar'; $g = $od['grand']; @endphp
@include('reports._head', ['title' => $title, 'subtitle' => 'As of '.\Illuminate\Support\Carbon::parse(now())->format('F j, Y'), 'mode' => 'none'])
<div class="card" style="margin-bottom:16px;display:flex;gap:32px;flex-wrap:wrap">
    <div><div class="muted">{{ $ar ? 'Invoices' : 'Bills' }} open</div><strong>{{ $g['count'] }}</strong></div>
    <div><div class="muted">Balance due</div><strong>{{ Money::format($g['due']) }}</strong></div>
    <div><div class="muted">Of which overdue</div><strong @if (Money::positive($g['overdue'])) style="color:#b3261e" @endif>{{ Money::format($g['overdue']) }}</strong></div>
</div>
<div class="card tbl"><table>
    <thead><tr><th>{{ $ar ? 'Invoice' : 'Bill' }}</th><th>Date</th><th>Due</th><th class="num">Days overdue</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance due</th></tr></thead>
    <tbody>
    @forelse ($od['groups'] as $grp)
        <tr style="background:rgba(127,127,127,.08)"><td colspan="4"><strong><a href="{{ route($ar ? 'customers.show' : 'vendors.show', $grp['id']) }}">{{ $grp['name'] }}</a></strong></td>
            <td class="num"><strong>{{ Money::format($grp['total']) }}</strong></td><td class="num"><strong>{{ Money::format($grp['paid']) }}</strong></td><td class="num"><strong>{{ Money::format($grp['due']) }}</strong></td></tr>
        @foreach ($grp['docs'] as $d)
            <tr><td style="padding-left:24px"><a href="{{ route($ar ? 'invoices.show' : 'bills.show', $d->id) }}">{{ $d->label }}</a></td><td>{{ Money::date($d->date) }}</td><td>{{ Money::date($d->due_date) }}</td>
                <td class="num">{{ $d->days ?: '' }}</td><td class="num">{{ Money::format($d->total) }}</td><td class="num">{{ Money::format($d->paid) }}</td><td class="num">{{ Money::format($d->balance_due) }}</td></tr>
        @endforeach
    @empty
        <tr><td colspan="7" class="muted" style="text-align:center;padding:24px">Nothing is unpaid.</td></tr>
    @endforelse
    <tr><td colspan="4"><strong>Total</strong></td><td class="num"><strong>{{ Money::format($g['total']) }}</strong></td><td class="num"><strong>{{ Money::format($g['paid']) }}</strong></td><td class="num"><strong>{{ Money::format($g['due']) }}</strong></td></tr>
    </tbody>
</table></div>
@endsection
