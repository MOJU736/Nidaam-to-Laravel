<!doctype html>
@php use App\Support\Money; @endphp
<html><head><meta charset="utf-8"><title>Items statement</title></head><body>
@include('construction.pdf._head', ['docTitle' => 'ITEMS STATEMENT'])
<table class="box"><tr>
    <td><span class="muted">Customer</span><br><strong>{{ $customer->name ?? 'All customers' }}</strong>@if (! empty($customer->address))<br>{{ $customer->address }}@endif</td>
    <td class="r"><span class="muted">Period</span><br>{{ Money::date($from) }} – {{ Money::date($to) }}@if ($item)<br>{{ $item->name }} only @endif</td>
</tr></table>
<table class="t">
    <thead><tr><th>Date</th><th>Invoice</th><th>Item</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Amount</th></tr></thead>
    <tbody>
    @foreach ($ci['groups'] as $grp)
        @unless ($customer)<tr class="sec"><td colspan="6">{{ $grp['name'] }}</td></tr>@endunless
        @foreach ($grp['lines'] as $l)
            <tr><td>{{ Money::date($l['date']) }}</td><td>{{ $l['doc'] }}</td><td>{{ $l['item'] }}@if ($l['description'])<br><span class="muted">{{ $l['description'] }}</span>@endif</td><td class="r">{{ $qty($l['qty']) }}</td><td class="r">{{ Money::format($l['price']) }}</td><td class="r">{{ Money::format($l['amount']) }}</td></tr>
        @endforeach
        <tr class="tot"><td colspan="3">Total {{ $grp['name'] }}</td><td class="r">{{ $qty($grp['qty']) }}</td><td></td><td class="r">{{ Money::format($grp['amount']) }}</td></tr>
    @endforeach
    </tbody>
</table>
<h2>Items summary</h2>
<table class="t"><thead><tr><th>Item</th><th class="r">Times</th><th class="r">Qty sold</th><th class="r">Returned</th><th class="r">Net amount</th></tr></thead>
    <tbody>@foreach ($ci['sum_items'] as $it)<tr><td>{{ $it['name'] }}</td><td class="r">{{ $it['times'] }}</td><td class="r">{{ $qty($it['sold']) }}</td><td class="r">{{ $it['returned'] ? $qty($it['returned']) : '' }}</td><td class="r">{{ Money::format($it['amount']) }}</td></tr>@endforeach
    <tr class="tot"><td>TOTAL</td><td></td><td></td><td></td><td class="r">{{ Money::format($ci['sum_total']['amount']) }}</td></tr></tbody></table>
</body></html>