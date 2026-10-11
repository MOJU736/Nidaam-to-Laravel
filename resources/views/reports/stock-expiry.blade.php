@extends('layouts.main')
@section('title', 'Stock Expiry')
@section('content')
@php use App\Support\Money; @endphp
@include('reports._head', ['title' => 'Stock Expiry', 'subtitle' => 'Expired, and expiring within '.$days.' days', 'mode' => 'none'])
<form class="card noprint" method="GET" action="{{ route('reports.stock-expiry') }}" style="margin-bottom:16px"><div class="rfilter">{!! $before !!}<button>Show</button></div></form>
<div class="card tbl"><table>
    <thead><tr><th>Item</th><th>Batch</th><th>Expires</th><th class="num">Days left</th><th class="num">Units</th><th class="num">Value at cost</th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
        @php $left = (int) floor((strtotime($r->expiry_date) - strtotime($today)) / 86400); @endphp
        <tr><td>{{ $r->item_name }}</td><td>{{ $r->batch_no }}</td><td>{{ Money::date($r->expiry_date) }}</td><td class="num" @if ($left < 0) style="color:var(--danger)" @endif>{{ $left < 0 ? 'expired '.abs($left).' d ago' : $left }}</td><td class="num">{{ rtrim(rtrim((string) $r->qty, '0'), '.') }}</td><td class="num">{{ Money::format(Money::mul($r->qty, $r->purchase_cost)) }}</td></tr>
    @empty
        <tr><td colspan="6" class="muted" style="text-align:center;padding:20px">No batches with stock are expired or expiring in this time. (Batches are kept for items tracked by batch and expiry date.)</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection