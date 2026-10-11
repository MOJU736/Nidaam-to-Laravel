@extends('layouts.main')
@section('title', 'Stock adjustment '.$adj->label)
@section('content')
@php use App\Services\Inventory\StockService as S; use App\Support\Money; @endphp
@include('items._tabs')
<div class="bar">
    <div><h1 style="margin:0">Stock adjustment {{ $adj->label }} <span class="pill {{ $adj->status === 'void' ? 'overdue' : '' }}">{{ $adj->status === 'void' ? 'Void' : 'Posted' }}</span></h1><a class="muted" href="{{ route('items.adjustments') }}">&larr; Stock adjustments</a></div>
    @if ($adj->status === 'posted')
        <form class="inline" method="POST" action="{{ route('items.adjustments.void', $adj->id) }}" onsubmit="return confirm('Void this adjustment? Each item\'s quantity moves back by what it changed, and its journal entry is removed.')">@csrf<button class="danger">Void</button></form>
    @endif
</div>
<div class="card"><dl class="kv">
    <dt>Date</dt><dd>{{ Money::date($adj->date) }}</dd><dt>Reason</dt><dd>{{ $reasons[$adj->reason] ?? $adj->reason }}</dd>
    @if ($adj->notes)<dt>Notes</dt><dd>{{ $adj->notes }}</dd>@endif
</dl></div>
<div class="card tbl" style="margin-top:16px">
    <table>
        <thead><tr><th>Item</th><th class="num">Was</th><th class="num">Now</th><th class="num">Change</th><th class="num">Avg cost</th><th class="num">Value</th></tr></thead>
        <tbody>
        @foreach ($adj->items as $l)
            @php $down = bccomp((string) $l->qty_change, '0', 2) < 0; @endphp
            <tr>
                <td>{{ $l->item_name ?? '(deleted item)' }}@if ($l->item_sku) <span class="muted">{{ $l->item_sku }}</span>@endif</td>
                <td class="num">{{ S::text($l->qty_before) }}</td><td class="num">{{ S::text($l->qty_after) }}</td>
                <td class="num" style="{{ $down ? 'color:var(--danger)' : '' }}"><strong>{{ $down ? '' : '+' }}{{ S::text($l->qty_change) }}</strong></td>
                <td class="num">{{ Money::format($l->unit_cost) }}</td><td class="num">{{ Money::format($l->value) }}</td>
            </tr>
        @endforeach
            <tr><td colspan="5"><strong>Total value</strong></td><td class="num"><strong>{{ Money::format($adj->total_value) }}</strong></td></tr>
        </tbody>
    </table>
</div>
@endsection
