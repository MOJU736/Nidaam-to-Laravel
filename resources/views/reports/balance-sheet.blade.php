@extends('layouts.main')
@section('title', 'Balance Sheet')
@section('content')
@php use App\Support\Money; @endphp
@include('reports._head', ['title' => 'Balance Sheet', 'subtitle' => 'As of '.$reports->longDate($asof), 'mode' => 'asof'])
@if (! $bs['balanced'])
    <div class="flash bad">This Balance Sheet does not balance: assets are {{ Money::format($bs['assets_total']) }} and liabilities &amp; equity are {{ Money::format($bs['liab_eq_total']) }} (difference {{ Money::format($bs['difference']) }}).
        @if ($bs['opdiff']) The opening balances are {{ Money::format($bs['opdiff']['amount']) }} short on the {{ $bs['opdiff']['dc'] === 'D' ? 'debit' : 'credit' }} side, which explains it. Fix them under Accounts. @endif</div>
@endif
<div class="card"><table class="qbr">
    <tr class="sec"><td colspan="2">Assets</td></tr>
    @include('reports._lines', ['rows' => $bs['asset_rows']])
    <tr class="grand"><td>Total Assets</td><td class="num">{{ Money::format($bs['assets_total']) }}</td></tr>
    <tr class="sec"><td colspan="2">Liabilities &amp; Equity</td></tr>
    @include('reports._lines', ['rows' => $bs['liab_rows']])
    <tr class="grand"><td>Total Liabilities &amp; Equity</td><td class="num">{{ Money::format($bs['liab_eq_total']) }}</td></tr>
</table></div>
@endsection
