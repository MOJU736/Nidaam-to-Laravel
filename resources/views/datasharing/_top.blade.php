{{-- Cycle bar, "to do in MTN" notes and the "cycle ended" warning. Included by the pages. --}}
@unless ($exporting)
    <nav class="subnav" aria-label="Data Sharing sections">
        @foreach ([['datasharing.index', 'Today'], ['datasharing.sims', 'SIM board'], ['datasharing.clients', 'Clients'], ['datasharing.money-in', 'Money In'], ['datasharing.money', 'Money Out'], ['datasharing.profit', 'Profit'], ['datasharing.reports', 'Reports'], ['datasharing.plans', 'Plans']] as [$r, $label])
            @if (\Illuminate\Support\Facades\Route::has($r) && auth()->user()->can('page', $r))<a class="{{ request()->routeIs($r) ? 'on' : '' }}" href="{{ route($r) }}">{{ $label }}</a>@endif
        @endforeach
    </nav>
    @php
        $dsDay = $ds->dayOfCycle($cyc);
        $dsLen = $ds->cycleDays($cyc);
        $dsLeft = max(0, $ds->daysFromToday($cyc['end']) + 1);
    @endphp
    @if (! empty($ended))
        <div class="ds-alert" role="alert">{{ $ended }} @if ($canManage)<a href="{{ route('datasharing.reports') }}">Close the books under Reports</a>@else Ask an administrator or manager to close them.@endif</div>
    @endif
    @if (empty($noCycleBar))
        <div class="ds-cyc">
            <b>Cycle {{ (int) $cyc['no'] }}</b>
            <small>{{ \App\Support\DsView::d($cyc['start']) }} &ndash; {{ \App\Support\DsView::d($cyc['end']) }}</small>
            <div class="meter" title="Day {{ $dsDay }} of {{ $dsLen }}"><i style="width:{{ round($dsDay / max(1, $dsLen) * 100) }}%"></i></div>
            <small>Day {{ $dsDay }} of {{ $dsLen }} &middot; {{ $dsLeft ? 'books close in '.$dsLeft.' day'.($dsLeft === 1 ? '' : 's') : 'ready to close' }}</small>
        </div>
    @endif
    @foreach ((array) $notes as $n)
        <div class="ds-note" role="note">
            <div><b>To do in MTN:</b> {{ $n['text'] }}</div>
            <form method="POST" action="{{ route('datasharing.note-done', $n['id']) }}">@csrf <input type="hidden" name="return" value="{{ $here }}"><button type="submit" class="ds-btn ds-btn--sm">Done in MTN</button></form>
        </div>
    @endforeach
@endunless
