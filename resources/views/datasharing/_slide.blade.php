@php
    $sl = $slide;
    $cls = fn ($al, $i) => isset($al[$i]) && $al[$i] === 'r' ? ' class="r"' : '';
@endphp
<div class="eyebrow">{{ $sl['eyebrow'] ?? '' }}</div>
<h2>{{ $sl['title'] }}</h2>
@if (! empty($sl['sub']))<div class="sub">{{ $sl['sub'] }}</div>@endif
@if (! empty($sl['kpis']))
    <div class="ds-kpis">@foreach ($sl['kpis'] as $k)<div class="ds-kpi {{ $k[3] }}"><small>{{ $k[0] }}</small><strong>{{ $k[1] }}</strong><span>{{ $k[2] }}</span></div>@endforeach</div>
@endif
@if (! empty($sl['bars']))
    <div class="ds-cols ds-cols--2">@foreach ($sl['bars'] as $b) @php $mx = max(1, max(array_map(fn ($i) => (float) $i[1], $b['items']))); @endphp
        <div class="ds-hb{{ ! empty($b['alt']) ? ' alt' : '' }}"><h3>{{ $b['title'] }}</h3>
            @foreach ($b['items'] as $i)<div class="r"><span>{{ $i[0] }}</span><div class="tr"><i style="width:{{ round((float) $i[1] / $mx * 100, 1) }}%"></i></div><b>{{ \App\Support\DsView::m($i[1]) }}</b></div>@endforeach
        </div>
    @endforeach</div>
@endif
@if (! empty($sl['table']))
    @php $t = $sl['table']; @endphp
    <div class="ds-tw" style="border:1px solid var(--line);border-radius:10px"><table class="ds-table">
        <thead><tr>@foreach ($t['head'] as $i => $h)<th{!! $cls($t['al'], $i) !!}>{{ $h }}</th>@endforeach</tr></thead>
        <tbody>@foreach ($t['rows'] as $r)<tr>@foreach ($r as $i => $v)<td{!! $cls($t['al'], $i) !!}>{{ $v }}</td>@endforeach</tr>@endforeach
        @if (! $t['rows'])<tr><td colspan="{{ count($t['head']) }}" class="ds-empty">Nothing here.</td></tr>@endif</tbody>
        @if (! empty($t['foot']))<tfoot><tr>@foreach ($t['foot'] as $i => $v)<td{!! $cls($t['al'], $i) !!}>{{ $v }}</td>@endforeach</tr></tfoot>@endif
    </table></div>
@endif
@if (! empty($sl['points']))<ul>@foreach ($sl['points'] as $p)<li>{{ $p }}</li>@endforeach</ul>@endif
