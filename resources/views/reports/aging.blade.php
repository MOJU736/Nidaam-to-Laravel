@extends('layouts.main')
@section('title', $title)
@section('content')
@php use App\Support\Money; use App\Services\Reports\ReportService; $t = $ag['totals']; $route = $side === 'ar' ? 'customers.show' : 'vendors.show'; @endphp
@include('reports._head', ['title' => $title, 'subtitle' => 'As of '.\Illuminate\Support\Carbon::parse($asof)->format('F j, Y'), 'mode' => 'asof'])
<p class="muted noprint" style="margin-top:0">Each open {{ $side === 'ar' ? 'invoice' : 'bill' }} sits in the column for how late it is. "Credits &amp; other" is money not applied to any {{ $side === 'ar' ? 'invoice' : 'bill' }} yet (negative) or an amount that didn't come from one, like an opening balance.</p>
<div class="card tbl"><table>
    <thead><tr><th>{{ $side === 'ar' ? 'Customer' : 'Vendor' }}</th>@foreach (ReportService::BUCKETS as $label)<th class="num">{{ $label }}</th>@endforeach<th class="num">Credits &amp; other</th><th class="num">Total</th></tr></thead>
    <tbody>
    @forelse ($ag['rows'] as $r)
        <tr><td><a href="{{ route($route, $r['id']) }}">{{ $r['name'] }}</a></td>@foreach ($r['buckets'] as $b)<td class="num">{{ Money::isZero($b) ? '' : Money::format($b) }}</td>@endforeach
            <td class="num">{{ Money::isZero($r['other']) ? '' : Money::format($r['other']) }}</td><td class="num"><strong>{{ Money::format($r['total']) }}</strong></td></tr>
    @empty
        <tr><td colspan="8" class="muted" style="text-align:center;padding:24px">Nothing outstanding.</td></tr>
    @endforelse
    <tr><td><strong>Total</strong></td>@foreach (array_keys(ReportService::BUCKETS) as $k)<td class="num"><strong>{{ Money::format($t[$k]) }}</strong></td>@endforeach<td class="num"><strong>{{ Money::format($t['other']) }}</strong></td><td class="num"><strong>{{ Money::format($t['total']) }}</strong></td></tr>
    </tbody>
</table></div>
@endsection
