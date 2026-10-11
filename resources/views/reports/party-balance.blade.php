@extends('layouts.main')
@section('title', $title)
@section('content')
@php use App\Support\Money; @endphp
@include('reports._head', ['title' => $title, 'subtitle' => 'As of '.\Illuminate\Support\Carbon::parse($asof)->format('F j, Y'), 'mode' => 'asof', 'extra' => $hideZero ? ['hide_zero' => 1] : []])
<p class="noprint" style="margin-top:0">
    @if ($hideZero)<a href="{{ request()->fullUrlWithQuery(['hide_zero' => null]) }}">Show zero balances</a>@else<a href="{{ request()->fullUrlWithQuery(['hide_zero' => 1]) }}">Hide zero balances</a>@endif
    <span class="muted"> · A negative balance means {{ $side === 'ar' ? 'the customer has paid in advance' : 'the vendor owes you money' }}.</span>
</p>
<div class="card tbl"><table>
    <thead><tr><th>{{ $c['one'] }}</th><th class="num">Balance</th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
        <tr><td><a href="{{ route($c['route'].'.show', $r['id']) }}">{{ $r['name'] }}</a></td><td class="num">{{ Money::format($r['balance']) }}</td></tr>
    @empty
        <tr><td colspan="2" class="muted" style="text-align:center;padding:24px">Nobody to show.</td></tr>
    @endforelse
    <tr><td><strong>Total</strong></td><td class="num"><strong>{{ Money::format($total) }}</strong></td></tr>
    </tbody>
</table></div>
@endsection
