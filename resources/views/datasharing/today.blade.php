@extends($exporting ? 'datasharing.bare' : 'layouts.main')
@section('title', 'Data Sharing - Today')
@section('content')
@php
    use App\Support\DsView as V;
    $m = fn ($v) => V::m($v); $d = fn ($v) => V::d($v);
    $all = $cd['all'];
    $late = array_values(array_filter($all, fn ($x) => $x['status'] === 'overdue'));
    usort($late, fn ($a, $b) => strcmp($a['next'], $b['next']));
    $due = array_values(array_filter($all, fn ($x) => $x['status'] === 'unpaid' && V::daysTo($x['next']) === 0));
    $week = array_values(array_filter($all, fn ($x) => $x['status'] === 'unpaid' && ($n = V::daysTo($x['next'])) > 0 && $n <= 7));
    usort($week, fn ($a, $b) => strcmp($a['next'], $b['next']));
    $cut = array_values(array_filter($late, fn ($x) => V::daysTo($x['next']) <= -3));
    $sumP = fn ($a) => array_sum(array_map(fn ($x) => $x['owed'] ?? $x['price'], $a));
    $tomorrow = array_values(array_filter($all, fn ($x) => $x['status'] === 'unpaid' && V::daysTo($x['next']) === 1));
    $queue = array_map(function ($x) use ($company, $m) { $n = V::daysTo($x['next']); return ['name' => $x['name'], 'phone' => V::wa($x), 'msg' => V::waMsg($x, $company),
        'info' => $m($x['owed'] ?? $x['price']).' · '.($n < 0 ? (-$n).' day'.($n === -1 ? '' : 's').' overdue' : ($n === 0 ? 'due today' : 'due tomorrow'))]; }, array_merge($late, $due, $tomorrow));
    $ups = [];
    foreach ($cd['sims'] as $sx) { foreach ($sx['clients'] as $x) { if ($x['rech_n'] >= 2 && ($p = V::upgradeFor($x, $sx['kind']))) { $ups[] = [$x, $p]; } } }
    $planN = count(array_filter($cd['pay_rows'], fn ($p) => $p['type'] === 'Plan payment'));
    $rechN = count($cd['pay_rows']) - $planN;
    $mcSims = array_values(array_filter($cd['sims'], function ($s) { if (empty($s['contact'])) { return false; } foreach ($s['clients'] as $x) { if ($x['status'] === 'overdue' || ($x['status'] === 'unpaid' && V::daysTo($x['next']) <= 7)) { return true; } } return false; }));
@endphp
@include('datasharing._style')
<section class="ds">
    <div class="ds-ph"><div><h1>Today</h1><p>{{ date('l j F') }} &middot; who to collect from and who to cut off</p></div>
        <div class="ds-tools">@if ($queue)<button type="button" class="ds-btn ds-btn--good" id="dsQBtn">&#9742; Reminders one by one ({{ count($queue) }})</button>@endif<a class="ds-btn" href="{{ route('datasharing.sims') }}">SIM board</a></div></div>
    @include('datasharing._top')
    <div class="ds-kpis">
        <div class="ds-kpi bad"><small>Overdue</small><strong>{{ count($late) }}</strong><span>{{ $m($sumP($late)) }} outstanding</span></div>
        <div class="ds-kpi warn"><small>Due today</small><strong>{{ count($due) }}</strong><span>{{ $m($sumP($due)) }}</span></div>
        <div class="ds-kpi"><small>Due in 7 days</small><strong>{{ count($week) }}</strong><span>{{ $m($sumP($week)) }}</span></div>
        <div class="ds-kpi good"><small>Collected this cycle</small><strong>{{ $m($cd['income']) }}</strong><span>{{ $planN }} plan payments &middot; {{ $rechN }} recharges</span></div>
    </div>
    @if (! $cd['sims'])
        <div class="ds-card"><div class="ds-empty"><p>No SIMs yet. Start by adding your SIM cards and the clients on each slot.</p><a class="ds-btn ds-btn--pri" href="{{ route('datasharing.sims') }}">Add your first SIM</a></div></div>
    @else
    @php
        $row = function ($x, $extra = '') use ($m, $company) {
            return view('datasharing._li', ['x' => $x, 'extra' => $extra, 'company' => $company])->render();
        };
    @endphp
    <div class="ds-cols">
        <div class="ds-card"><div class="ds-card__h"><h2>Overdue <span class="ds-n bad">{{ count($late) }}</span></h2></div>{!! $late ? implode('', array_map(fn ($x) => $row($x), $late)) : '<div class="ds-empty">Nobody here.</div>' !!}</div>
        <div class="ds-card"><div class="ds-card__h"><h2>Due today <span class="ds-n warn">{{ count($due) }}</span></h2></div>{!! $due ? implode('', array_map(fn ($x) => $row($x), $due)) : '<div class="ds-empty">Nobody here.</div>' !!}
            <div class="ds-card__h" style="border-top:1px solid var(--line)"><h2>This week <span class="ds-n warn">{{ count($week) }}</span></h2></div>{!! $week ? implode('', array_map(fn ($x) => $row($x), $week)) : '<div class="ds-empty">Nobody here.</div>' !!}</div>
        <div class="ds-card"><div class="ds-card__h"><h2>Cut off in MTN <span class="ds-n bad">{{ count($cut) }}</span></h2></div>
            <p class="ds-hint" style="padding:10px 16px 0">3 or more days overdue. Cut their data in MTN, then free the slot here.</p>
            {!! $cut ? implode('', array_map(function ($x) use ($row, $here) {
                $form = '<form method="POST" action="'.e(route('datasharing.client-remove', $x['id'])).'" style="display:inline" data-confirm="'.e('Remove '.$x['name'].'? Their slot becomes free. Their payments stay in the books.').'">'.csrf_field().'<input type="hidden" name="return" value="'.e($here).'"><button type="submit" class="ds-btn ds-btn--sm ds-btn--bad">Remove &amp; free slot</button></form>';
                return $row($x, $form);
            }, $cut)) : '<div class="ds-empty">Nobody here.</div>' !!}</div>
    </div>
    @if ($pkgDue)
    <div class="ds-card"><div class="ds-card__h"><h2>SIM packages to renew <span class="ds-n bad">{{ count($pkgDue) }}</span></h2><span class="ds-hint">Packages that run out within 3 days. When one runs out, every client on that SIM loses data.</span></div>
        @foreach ($pkgDue as $pk) @php $nd = (int) $pk['days']; @endphp
            <div class="ds-li"><div><b>{{ $pk['number'] }}</b><small>{{ (int) $pk['gb'] }} GB &middot; {{ (int) $pk['clients_n'] }} client{{ (int) $pk['clients_n'] === 1 ? '' : 's' }} &middot; package {{ $m($pk['cost']) }}</small></div>
                <div class="amt">{{ $d($pk['pkg_until']) }}<div><span class="ds-pill {{ $nd < 0 ? 'bad' : 'warn' }}">{{ $nd < 0 ? 'Ran out '.(-$nd).' day'.($nd === -1 ? '' : 's').' ago' : ($nd === 0 ? 'Runs out today' : ($nd === 1 ? 'Runs out tomorrow' : 'Runs out in '.$nd.' days')) }}</span></div></div>
                <div class="acts"><button type="button" class="ds-btn ds-btn--sm ds-btn--pri" data-ds-renew="{{ (int) $pk['id'] }}" data-number="{{ $pk['number'] }}" data-next="{{ $ds->addMonths($pk['pkg_until'] >= date('Y-m-d') ? $pk['pkg_until'] : date('Y-m-d'), 1) }}">Renewed</button></div></div>
        @endforeach
    </div>
    @endif
    @if ($ups)
    <div class="ds-card"><div class="ds-card__h"><h2>Suggest a bigger plan <span class="ds-n warn">{{ count($ups) }}</span></h2><span class="ds-hint">Clients who recharged 2 or more times this month</span></div>
        @foreach ($ups as [$x, $p])
            <div class="ds-li"><div><a href="{{ route('datasharing.client', $x['id']) }}"><b>{{ $x['name'] }}</b></a>{{ V::rcMark($x) }}<small>{{ (int) $x['gb'] }} GB at {{ $m($x['price']) }} &middot; recharged {{ (int) $x['rech_n'] }} times &middot; paid {{ $m($x['income']) }} this month</small></div>
                <div class="amt">{{ $ds->planLabel($p) }}<div><span class="ds-pill good">{{ $m($p['price']) }} a month</span></div></div>
                <div class="acts">{{ V::waButton(V::upMsg($x, $p, $company), $x['name'], V::wa($x), 'Offer', 'WhatsApp offer') }}<a class="ds-btn ds-btn--sm" href="{{ route('datasharing.client', $x['id']) }}">Change plan</a></div></div>
        @endforeach
    </div>
    @endif
    @if ($mcSims)
    <div class="ds-card"><div class="ds-card__h"><h2>Main contacts to call <span class="ds-n warn">{{ count($mcSims) }}</span></h2><span class="ds-hint">The person responsible for each shared SIM, with its slots that are overdue or due this week</span></div>
        @foreach ($mcSims as $sx)
            @php $c0 = $sx['contact']; $dueS = array_values(array_filter($sx['clients'], fn ($x) => $x['status'] === 'overdue' || ($x['status'] === 'unpaid' && V::daysTo($x['next']) <= 7))); $lateN = count(array_filter($dueS, fn ($x) => $x['status'] === 'overdue')); @endphp
            <div class="ds-li"><div><b><span class="ds-star" style="margin:0 4px 0 0">&#9733;</span>{{ $c0['name'] }}</b><small>{{ $c0['phone'] }} &middot; SIM {{ $sx['number'] }} &middot; {{ implode(', ', array_map(fn ($x) => $x['name'].($x['status'] === 'overdue' ? ' (overdue)' : ''), $dueS)) }}</small></div>
                <div class="amt">{{ $m($sumP($dueS)) }}<div><span class="ds-pill {{ $lateN ? 'bad' : 'warn' }}">{{ count($dueS) }} slot{{ count($dueS) === 1 ? '' : 's' }}{{ $lateN ? ' · '.$lateN.' overdue' : '' }}</span></div></div>
                <div class="acts">{{ V::waButton(V::mcMsg(['contact' => $c0, 'clients' => $dueS, 'number' => $sx['number']] + $sx, $company) ?? '', $c0['name'], $c0['whatsapp'] !== '' ? $c0['whatsapp'] : $c0['phone'], 'Remind') }}</div></div>
        @endforeach
    </div>
    @endif
    @endif
</section>
@unless ($exporting)
@include('datasharing._modals')
<dialog class="ds-modal" id="dsRenew">
    <form method="POST" action="{{ route('datasharing.sim-renew', 0) }}" id="dsRenewForm">@csrf <input type="hidden" name="return" value="{{ $here }}">
        <h3 id="dsRenewT">Package renewed</h3>
        <div class="ds-form"><label>The new package runs out on<input class="ds-field" type="date" name="until" required></label>
            <p class="ds-hint">This only moves the date. The package cost goes to Money Out each month when the books are closed.</p></div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Save</button></div>
    </form>
</dialog>
@if ($queue)
<dialog class="ds-modal" id="dsQ">
    <h3>Reminders one by one</h3>
    <div class="ds-form">
        <div style="display:flex;justify-content:space-between;gap:8px;align-items:baseline"><b id="dsQName"></b><span class="ds-hint" id="dsQN"></span></div>
        <div class="ds-hint" id="dsQInfo"></div>
        <div class="ds-wa" id="dsQText"></div>
        <p class="ds-hint" style="margin:0">Open WhatsApp sends nothing by itself: you check the message there and press send, then come back for the next one.</p>
    </div>
    <div class="ds-foot"><button type="button" class="ds-btn" data-close>Close</button><button type="button" class="ds-btn" id="dsQPrev">Back</button><button type="button" class="ds-btn" id="dsQSkip">Skip</button><a class="ds-btn ds-btn--good" id="dsQOpen" href="#" target="_blank" rel="noopener">Open WhatsApp</a></div>
</dialog>
<script>
(function () {
    var q = {{ \Illuminate\Support\Js::from($queue) }}, i = 0, done = {}, $ = function (s) { return document.querySelector(s); };
    var show = function () {
        if (i >= q.length) { $('#dsQName').textContent = 'All done'; $('#dsQN').textContent = q.length + ' of ' + q.length; $('#dsQInfo').textContent = Object.keys(done).length + ' opened in WhatsApp'; $('#dsQText').textContent = ''; $('#dsQOpen').hidden = true; $('#dsQSkip').hidden = true; return; }
        var it = q[i], ph = String(it.phone || '').replace(/[^0-9]/g, '');
        $('#dsQName').textContent = it.name; $('#dsQN').textContent = (i + 1) + ' of ' + q.length + (done[i] ? ' · opened' : '');
        $('#dsQInfo').textContent = it.phone + ' · ' + it.info; $('#dsQText').textContent = it.msg;
        var o = $('#dsQOpen'); o.href = 'https://wa.me/' + ph + '?text=' + encodeURIComponent(it.msg); o.hidden = ph.length < 9; $('#dsQSkip').hidden = false; $('#dsQPrev').disabled = i === 0;
    };
    $('#dsQBtn').addEventListener('click', function () { i = 0; show(); dsOpen('dsQ'); });
    $('#dsQOpen').addEventListener('click', function () { done[i] = true; setTimeout(function () { i++; show(); }, 300); });
    $('#dsQSkip').addEventListener('click', function () { i++; show(); });
    $('#dsQPrev').addEventListener('click', function () { if (i > 0) { i--; $('#dsQSkip').hidden = false; show(); } });
})();
</script>
@endif
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-ds-renew]'); if (!b) { return; }
    var f = document.getElementById('dsRenewForm');
    f.action = {{ \Illuminate\Support\Js::from(url('/datasharing/sim-renew')) }} + '/' + b.getAttribute('data-ds-renew'); f.querySelector('[name=until]').value = b.getAttribute('data-next');
    document.getElementById('dsRenewT').textContent = 'Package renewed on ' + b.getAttribute('data-number'); dsOpen('dsRenew');
});
</script>
@endunless
@endsection
