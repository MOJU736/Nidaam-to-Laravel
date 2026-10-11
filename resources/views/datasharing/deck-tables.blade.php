{{-- The presentation as plain tables, for the Excel file. --}}
@foreach ($slides as $sl)
    <table>
        <tr class="ds-group"><td>{{ $sl['title'] }}</td><td>{{ $sl['sub'] ?? '' }}</td></tr>
        @if (! empty($sl['kpis']))@foreach ($sl['kpis'] as $k)<tr><td>{{ $k[0] }}</td><td>{{ $k[1] }}</td><td>{{ $k[2] }}</td></tr>@endforeach @endif
        @if (! empty($sl['bars']))@foreach ($sl['bars'] as $b)<tr class="ds-group"><td>{{ $b['title'] }}</td></tr>@foreach ($b['items'] as $it)<tr><td>{{ $it[0] }}</td><td>{{ \App\Support\DsView::m($it[1]) }}</td></tr>@endforeach @endforeach @endif
        @if (! empty($sl['table']))<tr class="ds-group">@foreach ($sl['table']['head'] as $h)<td>{{ $h }}</td>@endforeach</tr>
            @foreach ($sl['table']['rows'] as $r)<tr>@foreach ($r as $v)<td>{{ $v }}</td>@endforeach</tr>@endforeach
            @if (! empty($sl['table']['foot']))<tr class="ds-group">@foreach ($sl['table']['foot'] as $v)<td>{{ $v }}</td>@endforeach</tr>@endif
        @endif
        @if (! empty($sl['points']))@foreach ($sl['points'] as $p)<tr><td>{{ $p }}</td></tr>@endforeach @endif
    </table>
@endforeach
