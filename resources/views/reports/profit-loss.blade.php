@extends('layouts.main')
@section('title', 'Profit & Loss')
@section('content')
@php use App\Support\Money; @endphp
@include('reports._head', ['title' => 'Profit & Loss', 'subtitle' => $reports->longDate($from).' through '.$reports->longDate($to), 'mode' => 'range'])
@if (Money::cmp($pl['check'], $pl['net_income']) !== 0)
    <div class="flash bad">The sections add up to {{ Money::format($pl['net_income']) }} but the income and expense accounts add up to {{ Money::format($pl['check']) }}. Some account sits outside the groups this report reads - check Accounts.</div>
@endif
<div class="card"><table class="qbr">
    <tr class="sec"><td colspan="2">Income</td></tr>
    @include('reports._lines', ['rows' => $pl['income_rows']])
    <tr class="total"><td>Total Income</td><td class="num">{{ Money::format($pl['income_total']) }}</td></tr>
    @if ($pl['has_cogs'])
        <tr class="sec"><td colspan="2">Cost of Goods Sold</td></tr>
        @include('reports._lines', ['rows' => $pl['cogs_rows']])
        <tr class="total"><td>Total Cost of Goods Sold</td><td class="num">{{ Money::format($pl['cogs_total']) }}</td></tr>
        <tr class="grand"><td>Gross Profit</td><td class="num">{{ Money::format($pl['gross']) }}</td></tr>
    @endif
    <tr class="sec"><td colspan="2">Expense</td></tr>
    @include('reports._lines', ['rows' => $pl['expense_rows']])
    <tr class="total"><td>Total Expense</td><td class="num">{{ Money::format($pl['expense_total']) }}</td></tr>
    <tr class="grand"><td>Net Ordinary Income</td><td class="num">{{ Money::format($pl['net_ordinary']) }}</td></tr>
    @if ($pl['has_other'])
        <tr class="sec"><td colspan="2">Other Income</td></tr>
        @include('reports._lines', ['rows' => $pl['oi_rows']])
        <tr class="total"><td>Total Other Income</td><td class="num">{{ Money::format($pl['oi_total']) }}</td></tr>
        <tr class="sec"><td colspan="2">Other Expense</td></tr>
        @include('reports._lines', ['rows' => $pl['oe_rows']])
        <tr class="total"><td>Total Other Expense</td><td class="num">{{ Money::format($pl['oe_total']) }}</td></tr>
        <tr class="grand"><td>Net Other Income</td><td class="num">{{ Money::format($pl['net_other']) }}</td></tr>
    @endif
    <tr class="grand"><td>Net Income</td><td class="num">{{ Money::format($pl['net_income']) }}</td></tr>
</table></div>
@endsection
