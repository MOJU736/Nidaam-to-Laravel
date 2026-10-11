@extends('layouts.main')
@section('title', 'Tax Summary')
@section('content')
@php use App\Support\Money; $t = $tax['tot']; @endphp
@include('reports._head', ['title' => 'Tax Summary', 'subtitle' => $reports->longDate($from).' through '.$reports->longDate($to), 'mode' => 'range'])
<p class="muted noprint" style="margin-top:0">From issued invoices, bills and credit notes in this period. A positive net is tax you owe; a negative one is tax you can claim back.</p>
<div class="card tbl"><table>
    <thead><tr><th>Rate</th><th class="num">Taxable sales</th><th class="num">Tax on sales</th><th class="num">Taxable purchases</th><th class="num">Tax on purchases</th><th class="num">Net</th></tr></thead>
    <tbody>
    @forelse ($tax['rates'] as $r)
        <tr><td>{{ $r['label'] }}</td><td class="num">{{ Money::format($r['sales_taxable']) }}</td><td class="num">{{ Money::format($r['sales_tax']) }}</td><td class="num">{{ Money::format($r['purch_taxable']) }}</td><td class="num">{{ Money::format($r['purch_tax']) }}</td><td class="num">{{ Money::format(Money::sub($r['sales_tax'], $r['purch_tax'])) }}</td></tr>
    @empty
        <tr><td colspan="6" class="muted" style="text-align:center;padding:24px">No tax was charged in this period.</td></tr>
    @endforelse
    <tr><td><strong>Total</strong></td><td class="num"><strong>{{ Money::format($t['sales_taxable']) }}</strong></td><td class="num"><strong>{{ Money::format($t['sales_tax']) }}</strong></td><td class="num"><strong>{{ Money::format($t['purch_taxable']) }}</strong></td><td class="num"><strong>{{ Money::format($t['purch_tax']) }}</strong></td><td class="num"><strong>{{ Money::format($tax['net']) }}</strong></td></tr>
    </tbody>
</table></div>
@if ($tax['sales'] || $tax['purch'])
    <h3 style="margin:22px 0 8px">Documents</h3>
    <div class="card tbl"><table>
        <thead><tr><th>Document</th><th>Date</th><th>Name</th><th class="num">Taxable</th><th class="num">Tax</th></tr></thead>
        <tbody>
        @foreach (array_merge($tax['sales'], $tax['purch']) as $d)
            <tr><td>{{ $d->label }} {{ $d->number }}</td><td>{{ Money::date($d->date) }}</td><td>{{ $d->party }}</td><td class="num">{{ Money::format($d->taxable) }}</td><td class="num">{{ Money::format($d->tax) }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
@endif
@endsection
