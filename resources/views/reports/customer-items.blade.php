@extends('layouts.main')
@section('title', $title)
@section('content')
@php
    use App\Support\Money;
    $g = $ci['grand']; $one = (bool) $customer; $sp = $profit;
    $pct = fn ($m) => $m === null ? '' : number_format($m, 1).'%';
    $dates = ['from' => $from, 'to' => $to];
@endphp
@include('reports._head', ['title' => $title, 'subtitle' => $sub, 'mode' => 'range', 'before' => $before, 'extra' => []])
<p class="muted noprint" style="margin-top:0">Every item on issued invoices in these dates, with the invoice number; returns and credit notes show as minus lines. Amounts are after line discounts and before tax.</p>
@if ($one)
    <div class="card" style="margin-bottom:12px"><strong><a href="{{ route('customers.show', $customer->id) }}">{{ $customer->name }}</a></strong>
        <span class="muted">{{ $customer->address ? ' · '.str_replace("\n", ', ', $customer->address) : '' }}{{ $customer->phone || $customer->email ? ' · '.trim($customer->phone.($customer->phone && $customer->email ? ' · ' : '').$customer->email) : '' }}</span></div>
@endif
<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(180px,1fr));margin-bottom:12px">
    @unless ($one)<div class="card"><div class="muted">Customers</div><strong style="font-size:20px">{{ count($ci['groups']) }}</strong></div>@endunless
    <div class="card"><div class="muted">Invoices &amp; credit notes</div><strong style="font-size:20px">{{ $g['docs'] }}</strong></div>
    <div class="card"><div class="muted">Different items</div><strong style="font-size:20px">{{ count($g['items']) }}</strong></div>
    <div class="card"><div class="muted">Total taken</div><strong style="font-size:20px">{{ Money::format($g['amount']) }}</strong></div>
    @if ($sp)<div class="card"><div class="muted">Gross profit</div><strong style="font-size:20px">{{ Money::format($ci['sum_total']['profit']) }}</strong> <span class="muted">{{ $pct($ci['sum_total']['margin']) }}</span></div>@endif
</div>
@if (! $ci['groups'])
    <div class="card"><p class="muted" style="margin:0">No items on issued invoices for {{ $one ? $customer->name : 'any customer' }} in these dates.</p></div>
@elseif ($by === 'item')
    <div class="card tbl"><table>
        <thead><tr><th>Item / customer</th><th class="num">Times</th><th class="num">Qty sold</th><th class="num">Returned</th><th class="num">Net amount</th><th class="num">Last price</th><th>Last bought</th>@if ($sp)<th class="num">Profit</th><th class="num">Margin</th>@endif</tr></thead>
        <tbody>
        @foreach ($ci['item_groups'] as $ig) @php $tt = $ig['total']; @endphp
            <tr style="background:var(--bg)"><td colspan="{{ $sp ? 9 : 7 }}"><strong>{{ $ig['name'] }}</strong></td></tr>
            @foreach ($ig['rows'] as $r)
                <tr><td style="padding-left:26px"><a href="{{ route('reports.customer-items', ['customer' => $r['id']] + $dates) }}">{{ $r['name'] }}</a></td><td class="num">{{ $r['times'] }}</td><td class="num">{{ $qty($r['sold']) }}</td><td class="num">{{ $r['returned'] ? $qty($r['returned']) : '' }}</td>
                    <td class="num">{{ Money::format($r['amount']) }}</td><td class="num">{{ $r['last_price'] !== null ? Money::format($r['last_price']) : '' }}</td><td>{{ $r['last_date'] ? Money::date($r['last_date']) : '' }}</td>@if ($sp)<td class="num">{{ Money::format($r['profit']) }}</td><td class="num">{{ $pct($r['margin']) }}</td>@endif</tr>
            @endforeach
            <tr style="font-weight:600"><td>Total {{ $ig['name'] }} · {{ count($ig['rows']) }} customer{{ count($ig['rows']) === 1 ? '' : 's' }}</td><td class="num">{{ $tt['times'] }}</td><td class="num">{{ $qty($tt['sold']) }}</td><td class="num">{{ $tt['returned'] ? $qty($tt['returned']) : '' }}</td><td class="num">{{ Money::format($tt['amount']) }}</td><td></td><td></td>@if ($sp)<td class="num">{{ Money::format($tt['profit']) }}</td><td class="num">{{ $pct($tt['margin']) }}</td>@endif</tr>
        @endforeach
        </tbody>
    </table></div>
@else
    <div class="card tbl"><table>
        <thead><tr><th>Date</th><th>Invoice</th><th>Item</th><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
        <tbody>
        @foreach ($ci['groups'] as $grp)
            @unless ($one)<tr style="background:var(--bg)"><td colspan="7"><a href="{{ route('reports.customer-items', ['customer' => $grp['id']] + $dates) }}"><strong>{{ $grp['name'] }}</strong></a></td></tr>@endunless
            @foreach ($grp['lines'] as $l)
                <tr @if ($l['kind'] === 'credit') style="color:var(--danger)" @endif><td>{{ Money::date($l['date']) }}</td><td><a href="{{ $l['url'] }}">{{ $l['doc'] }}</a></td><td>{{ $l['item'] }}</td><td>{{ $l['description'] }}@if ($l['discount']) <span class="muted">({{ Money::format($l['discount']) }} off)</span>@endif</td>
                    <td class="num">{{ $qty($l['qty']) }}</td><td class="num">{{ Money::format($l['price']) }}</td><td class="num">{{ Money::format($l['amount']) }}</td></tr>
            @endforeach
            <tr style="font-weight:600"><td colspan="4">Total {{ $grp['name'] }} · {{ count($grp['docs']) }} document{{ count($grp['docs']) === 1 ? '' : 's' }}</td><td class="num">{{ $qty($grp['qty']) }}</td><td></td><td class="num">{{ Money::format($grp['amount']) }}</td></tr>
        @endforeach
        @unless ($one)<tr style="font-weight:700"><td colspan="4">TOTAL</td><td class="num">{{ $qty($g['qty']) }}</td><td></td><td class="num">{{ Money::format($g['amount']) }}</td></tr>@endunless
        </tbody>
    </table></div>
    <h2>Items summary</h2>
    <div class="card tbl"><table>
        <thead><tr><th>Item</th><th class="num">Times</th><th class="num">Qty sold</th><th class="num">Returned</th><th class="num">Net amount</th><th class="num">Last price</th><th>Last sold</th>@if ($sp)<th class="num">Cost</th><th class="num">Profit</th><th class="num">Margin</th>@endif</tr></thead>
        <tbody>
        @foreach ($ci['sum_items'] as $it)
            <tr><td>{{ $it['name'] }}@if ($sp && $it['no_cost']) <small class="muted">no cost recorded</small>@endif</td><td class="num">{{ $it['times'] }}</td><td class="num">{{ $qty($it['sold']) }}</td><td class="num">{{ $it['returned'] ? $qty($it['returned']) : '' }}</td><td class="num">{{ Money::format($it['amount']) }}</td>
                <td class="num">{{ $it['last_price'] !== null ? Money::format($it['last_price']) : '' }}</td><td>{{ $it['last_date'] ? Money::date($it['last_date']) : '' }}</td>@if ($sp)<td class="num">{{ Money::format($it['cost']) }}</td><td class="num"><strong>{{ Money::format($it['profit']) }}</strong></td><td class="num">{{ $pct($it['margin']) }}</td>@endif</tr>
        @endforeach
        </tbody>
    </table></div>
@endif
@if ($one && $ci['groups'])
<form class="card noprint" method="POST" action="{{ route('reports.customer-items.email', ['customer' => $customer->id, 'item' => $item->id ?? null, 'from' => $from, 'to' => $to]) }}" style="max-width:620px;margin-top:16px">
    @csrf <h3 style="margin:0 0 6px">Email this statement to the customer</h3>
    <div class="actions" style="align-items:end"><div style="flex:1"><input name="email" value="{{ $email }}" placeholder="customer@example.com"></div><button class="ghost" onclick="return confirm('Send the statement PDF by email now? (cost and profit are never on it)')">Send</button></div>
    @unless ($email)<label style="font-weight:400"><input type="checkbox" name="save_email" value="1" checked> Save this address on the customer</label>@endunless
</form>
@endif
@endsection