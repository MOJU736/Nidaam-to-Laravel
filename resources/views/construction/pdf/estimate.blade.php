<!doctype html>
@php use App\Support\Money; $q = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; $types = \App\Services\Construction\ConstructionService::COST_TYPES; @endphp
<html><head><meta charset="utf-8"><title>Estimate</title></head><body>
@include('construction.pdf._head', ['docTitle' => 'ESTIMATE '.$p->number.'-'.$e->number])
<table class="box"><tr>
    <td><span class="muted">Project</span><br><strong>#{{ $p->number }} {{ $p->name }}</strong>@if ($p->site)<br>{{ $p->site }}@endif</td>
    <td><span class="muted">Client</span><br><strong>{{ $customer->name ?? $p->customer_name }}</strong>@if (! empty($customer->address))<br>{{ $customer->address }}@endif</td>
    <td class="r"><span class="muted">Date</span><br>{{ Money::date($e->date) }}</td>
</tr></table>
<div style="font-size:13px;font-weight:bold;margin:6px 0">{{ $e->title ?: 'Bill of Quantities' }}</div>
<table class="t">
    <thead><tr><th style="width:26px">#</th><th>Description</th><th>Unit</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead>
    <tbody>
    @foreach ($e->sections as $si => $s)
        @continue ($s->items->isEmpty())
        <tr class="sec"><td colspan="5">{{ chr(65 + ($si % 26)) }}. {{ $s->name }}</td><td class="r">{{ Money::format($s->total) }}</td></tr>
        @foreach ($s->items as $ii => $it)
            <tr><td>{{ $ii + 1 }}</td><td>{{ $it->description }}</td><td>{{ $it->unit }}</td><td class="r">{{ $q($it->qty) }}</td><td class="r">{{ Money::format($it->rate) }}</td><td class="r">{{ Money::format($it->amount) }}</td></tr>
        @endforeach
    @endforeach
    </tbody>
    <tfoot>
        <tr class="tot"><td colspan="5" class="r">Total{{ Money::positive($e->tax_total) ? ' before tax' : '' }}</td><td class="r">{{ Money::format($e->subtotal) }}</td></tr>
        @if (Money::positive($e->tax_total))
            <tr><td colspan="5" class="r">{{ $e->tax_name ?: 'Tax' }} {{ $q($e->tax_percent) }}%</td><td class="r">{{ Money::format($e->tax_total) }}</td></tr>
            <tr class="tot"><td colspan="5" class="r">Total</td><td class="r">{{ Money::format($e->total) }}</td></tr>
        @endif
    </tfoot>
</table>
@if ($e->notes)<h2>Notes</h2><div>{!! nl2br(e($e->notes)) !!}</div>@endif
<table class="sign"><tr><td>Client acceptance</td><td class="gap"></td><td>For {{ $company['name'] }}</td></tr></table>
</body></html>
