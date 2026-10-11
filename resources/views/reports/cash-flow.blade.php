@extends('layouts.main')
@section('title', 'Statement of Cash Flows')
@section('content')
@php
    use App\Support\Money;
    $titles = ['operating' => 'Operating Activities', 'investing' => 'Investing Activities', 'financing' => 'Financing Activities'];
@endphp
@include('reports._head', ['title' => 'Statement of Cash Flows', 'subtitle' => $reports->longDate($from).' through '.$reports->longDate($to), 'mode' => 'range'])
@if (! $cf['ties_out'])<div class="flash bad">Cash at the end of the period per this report ({{ Money::format($cf['cash_end_computed']) }}) does not match the bank and cash accounts ({{ Money::format($cf['cash_end_actual']) }}). Check the opening balances and any account that is not on the Balance Sheet.</div>@endif
@if (! $cf['has_cash_ledger'])<div class="flash bad">No account is marked as a Bank/Cash account, so cash shows 0.00. Tick "Bank/Cash account" on your bank and cash accounts under Accounts.</div>@endif
<div class="card"><table class="qbr">
    @foreach ($titles as $k => $t)
        @continue ($k !== 'operating' && ! $cf['sections'][$k])
        <tr class="sec"><td colspan="2">{{ $t }}</td></tr>
        @if ($k === 'operating')
            <tr><td style="padding-left:28px"><a href="{{ route('reports.profit-loss', ['from' => $from, 'to' => $to]) }}">Net Income</a></td><td class="num">{{ Money::format($cf['net_profit']) }}</td></tr>
            @if ($cf['sections']['operating'])<tr class="group"><td colspan="2" style="padding-left:28px">Adjustments to reconcile Net Income to net cash from operations:</td></tr>@endif
        @endif
        @foreach ($cf['sections'][$k] as $l)<tr><td style="padding-left:{{ $k === 'operating' ? 48 : 28 }}px">{{ $l['label'] }}</td><td class="num">{{ Money::format($l['cash_effect']) }}</td></tr>@endforeach
        <tr class="total"><td>Net cash provided by {{ $t }}</td><td class="num">{{ Money::format($cf['totals'][$k]) }}</td></tr>
    @endforeach
    <tr class="grand"><td>Net cash increase for period</td><td class="num">{{ Money::format($cf['net_change']) }}</td></tr>
    <tr><td>Cash at beginning of period</td><td class="num">{{ Money::format($cf['cash_begin']) }}</td></tr>
    <tr class="grand"><td>Cash at end of period</td><td class="num">{{ Money::format($cf['cash_end_computed']) }}</td></tr>
</table></div>
@endsection
