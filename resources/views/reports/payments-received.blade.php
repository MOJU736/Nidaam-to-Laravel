@extends('layouts.main')
@section('title', 'Payments Received')
@section('content')
@php use App\Support\Money; $acc = $pr['group'] === 'account'; @endphp
@include('reports._head', ['title' => 'Payments Received', 'subtitle' => $reports->longDate($from).' through '.$reports->longDate($to), 'mode' => 'range', 'before' => $before, 'extra' => []])
<p class="muted noprint" style="margin-top:0">Every payment received from customers in these dates (voided ones left out), so you can check the cash box and the bank.</p>
@if ($pr['count'])
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));margin-bottom:14px">
    <div class="card"><div class="muted">Total received</div><div style="font-size:22px;font-weight:700">{{ Money::format($pr['total']) }}</div><div class="muted" style="font-size:12px">{{ $pr['count'] }} payment{{ $pr['count'] == 1 ? '' : 's' }}</div></div>
    <div class="card"><div class="muted">How it was paid</div>@foreach ($pr['by_method'] as $k => $v)<div style="display:flex;justify-content:space-between"><span>{{ $k }}</span><strong>{{ Money::format($v) }}</strong></div>@endforeach</div>
    <div class="card"><div class="muted">Where it went</div>@foreach ($pr['by_account'] as $k => $v)<div style="display:flex;justify-content:space-between"><span>{{ $k }}</span><strong>{{ Money::format($v) }}</strong></div>@endforeach</div>
</div>
@endif
@forelse ($pr['groups'] as $g)
    <div class="card tbl" style="margin-bottom:12px"><div style="margin-bottom:4px"><strong>{{ $g['name'] }}</strong></div>
        <table>
            <thead><tr><th>Date</th><th>Payment</th><th>Customer</th><th>{{ $acc ? 'Method' : 'Account' }}</th><th>Reference</th><th class="num">Amount</th></tr></thead>
            <tbody>@foreach ($g['rows'] as $r)<tr><td>{{ Money::date($r->date) }}</td><td><a href="{{ route('payments.show', $r->id) }}">{{ $r->number }}</a></td><td>{{ $r->customer_name }}</td><td>{{ $acc ? $r->method_label : $r->account_label }}</td><td>{{ $r->reference }}</td><td class="num">{{ Money::format($r->amount) }}</td></tr>@endforeach</tbody>
            <tfoot><tr><th colspan="5">Total {{ $g['name'] }}</th><th class="num">{{ Money::format($g['total']) }}</th></tr></tfoot>
        </table></div>
@empty
    <div class="card"><p class="muted" style="margin:0">No payments were received in these dates.</p></div>
@endforelse
@endsection
