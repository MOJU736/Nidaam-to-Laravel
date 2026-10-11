@extends('layouts.main')
@section('title', 'Profit by Item')
@section('content')
@php use App\Support\Money; $q = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.') ?: '0'; @endphp
@include('reports._head', ['title' => 'Profit by Item', 'subtitle' => $reports->longDate($from).' through '.$reports->longDate($to), 'mode' => 'range', 'before' => $before, 'extra' => []])
<p class="muted noprint" style="margin-top:0">Issued invoices less returns. Cost is the stock cost booked when each invoice was issued; services and lines without a stock item have no cost, so all of their amount counts as profit. Amounts are after line discounts and before tax; a discount on a whole invoice is not split across items.</p>
<div class="card tbl"><table>
    <thead><tr><th>Item</th><th class="num">Qty sold</th><th class="num">Returned</th><th class="num">Net amount</th><th class="num">Cost</th><th class="num">Profit</th><th class="num">Margin</th><th>Last sold</th></tr></thead>
    <tbody>
    @forelse ($p['rows'] as $r)
        <tr><td>{{ $r['name'] }}@if ($r['no_cost'])<span class="muted" title="Some lines have no cost recorded"> *</span>@endif</td><td class="num">{{ $q($r['sold']) }}</td><td class="num">{{ $q($r['returned']) }}</td><td class="num">{{ Money::format($r['amount']) }}</td>
            <td class="num">{{ Money::format($r['cost']) }}</td><td class="num" @if (Money::cmp($r['profit'], 0) < 0) style="color:var(--danger)" @endif>{{ Money::format($r['profit']) }}</td><td class="num">{{ $r['margin'] === null ? '' : number_format($r['margin'], 1).'%' }}</td>
            <td>{{ $r['last_date'] ? Money::date($r['last_date']).' @ '.Money::format($r['last_price']) : '' }}</td></tr>
    @empty
        <tr><td colspan="8" class="muted" style="text-align:center;padding:20px">No sales in these dates.</td></tr>
    @endforelse
    </tbody>
    @if ($p['rows'])@php $t = $p['total']; @endphp<tfoot><tr><th>TOTAL</th><th class="num">{{ $q($t['sold']) }}</th><th class="num">{{ $q($t['returned']) }}</th><th class="num">{{ Money::format($t['amount']) }}</th><th class="num">{{ Money::format($t['cost']) }}</th><th class="num">{{ Money::format($t['profit']) }}</th><th class="num">{{ $t['margin'] === null ? '' : number_format($t['margin'], 1).'%' }}</th><th></th></tr></tfoot>@endif
</table></div>
@endsection
