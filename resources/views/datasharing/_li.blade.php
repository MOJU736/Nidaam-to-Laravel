@php use App\Support\DsView as V; @endphp
<div class="ds-li"><div><a href="{{ route('datasharing.client', $x['id']) }}"><b>{{ $x['name'] }}</b></a>{{ V::star($x) }}{{ V::rcMark($x) }}<small>{{ $x['phone'] }} &middot; {{ (int) $x['gb'] }} GB on {{ $x['sim'] }}@if (isset($x['used']) && $x['used'] !== null) &middot; {{ V::usageTxt($x) }}@endif</small>@if (! empty($x['mc']))<span class="ds-mcby">Main contact: {{ $x['mc'] }}</span>@endif</div>
    <div class="amt">{{ V::m($x['owed'] ?? $x['price']) }}<div>{{ V::pill($x) }}</div></div>
    <div class="acts">{{ V::payButton($x) }}{{ V::waButton(V::waMsg($x, $company), $x['name'], V::wa($x)) }}{!! $extra !!}</div></div>
