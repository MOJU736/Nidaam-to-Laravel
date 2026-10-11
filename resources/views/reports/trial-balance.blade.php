@extends('layouts.main')
@section('title', 'Trial Balance')
@section('content')
@php use App\Support\Money; @endphp
@include('reports._head', ['title' => 'Trial Balance', 'subtitle' => 'As of '.$reports->longDate($asof), 'mode' => 'asof'])
<p class="muted noprint" style="margin-top:0"><a href="{{ route('reports.trial-balance', ['asof' => $asof, 'view' => 'detail']) }}">Show details</a>: opening balance, debits, credits and closing balance of every account.</p>
@if (! $tb['balanced'])
    <div class="flash bad">The trial balance does not balance: debits are {{ Money::format($tb['debit']) }} and credits are {{ Money::format($tb['credit']) }}. Check the opening balances under Accounts.</div>
@endif
<div class="card"><table class="qbr">
    <thead><tr><td></td><td class="num"><strong>Debit</strong></td><td class="num"><strong>Credit</strong></td></tr></thead>
    @forelse ($tb['rows'] as $r)
        <tr><td>@if ($r['url'])<a href="{{ $r['url'] }}">{{ $r['label'] }}</a>@else{{ $r['label'] }}@endif</td>
            <td class="num">{{ $r['debit'] !== null ? Money::format($r['debit']) : '' }}</td><td class="num">{{ $r['credit'] !== null ? Money::format($r['credit']) : '' }}</td></tr>
    @empty
        <tr><td colspan="3" class="muted" style="text-align:center;padding:24px">No balances yet.</td></tr>
    @endforelse
    <tr class="grand"><td>Total</td><td class="num">{{ Money::format($tb['debit']) }}</td><td class="num">{{ Money::format($tb['credit']) }}</td></tr>
</table></div>
@endsection
