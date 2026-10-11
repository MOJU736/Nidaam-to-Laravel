@extends($exporting ? 'datasharing.bare' : 'layouts.main')
@section('title', 'Data Sharing - SIM board')
@section('content')
@php
    use App\Support\DsView as V;
    $m = fn ($v) => V::m($v); $d = fn ($v) => V::d($v);
    $match = fn ($x) => $f === 'all' ? true : ($f === 'recharged' ? $x['rech_n'] > 0 : ($f === 'free' ? false : $x['status'] === $f));
    $shown = array_values(array_filter($cd['sims'], function ($s) use ($f, $match) {
        if ($f === 'all') { return true; }
        if ($f === 'free') { return $s['free_slots'] > 0; }
        foreach ($s['clients'] as $x) { if ($match($x)) { return true; } }
        return false;
    }));
    $count = ['all' => count($cd['sims']), 'free' => array_sum(array_column($cd['sims'], 'free_slots'))];
    foreach (['paid', 'unpaid', 'overdue'] as $k) { $count[$k] = count(array_filter($cd['all'], fn ($x) => $x['status'] === $k)); }
    $count['recharged'] = count(array_filter($cd['all'], fn ($x) => $x['rech_n'] > 0));
    $fNames = ['all' => 'All', 'free' => 'Free slots', 'paid' => 'Paid', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'recharged' => 'Recharged'];
    $keep = array_filter(['type' => request()->query('type'), 'sim' => request()->query('sim'), 'q' => $q]);
    $url = fn ($ff, $vv) => route('datasharing.sims', array_filter(['f' => $ff, 'v' => $vv]) + $keep);
    $simInfo = []; foreach ($simRows as $sr) { $simInfo[$sr['id']] = $sr; }
    $plansBy = ['shared' => [], 'full' => []];
    foreach ($plans as $p) { $plansBy[(int) $p['full'] ? 'full' : 'shared'][] = ['gb' => (int) $p['gb'], 'price' => number_format((float) $p['price'], 2, '.', ''), 'name' => $ds->planLabel($p)]; }
    $moveData = [];
    foreach ($cd['sims'] as $s) { $moveData[$s['id']] = ['number' => $s['number'], 'kind' => $s['kind'], 'spare' => $s['spare'], 'free' => $s['free_slots'], 'clients' => array_map(fn ($x) => ['id' => $x['id'], 'name' => $x['name'], 'gb' => $x['gb'], 'last' => isset($x['used']) && $x['used'] !== null ? V::gbf($x['used']).' used, '.V::gbf($x['left']).' left on '.$d($x['read_on']) : 'no reading yet'], $s['clients'])]; }
    $qs = array_filter(['f' => $f, 'v' => 'list', 'q' => $q, 'type' => request()->query('type'), 'sim' => request()->query('sim')]);
@endphp
@include('datasharing._style')
<section class="ds">
    @unless ($exporting)
    <div class="ds-ph"><div><h1>SIM board</h1><p>{{ count($cd['sims']) }} SIM{{ count($cd['sims']) === 1 ? '' : 's' }} &middot; {{ count($cd['all']) }} clients &middot; {{ $count['free'] }} free slots</p></div>
        <div class="ds-tools">
            <div class="ds-seg" role="group" aria-label="View"><a href="{{ $url($f, 'cards') }}" class="{{ $v === 'cards' ? 'on' : '' }}">Cards</a><a href="{{ $url($f, 'list') }}" class="{{ $v === 'list' ? 'on' : '' }}">List</a></div>
            @include('datasharing._export', ['route' => 'datasharing.sims', 'qs' => $qs])
            @if ($canManage)<a class="ds-btn" href="{{ route('datasharing.plans') }}">Plan settings</a>@endif
            <button type="button" class="ds-btn ds-btn--pri" data-open="dsNewSim">+ New SIM</button>
        </div></div>
    @include('datasharing._top')
    <div class="ds-seg" role="group" aria-label="Show">@foreach ($fNames as $k => $nm)<a href="{{ $url($k, $v) }}" class="{{ $f === $k ? 'on' : '' }}">{{ $nm }}<small>{{ (int) $count[$k] }}</small></a>@endforeach</div>
    @include('datasharing._filters', ['fltAction' => 'datasharing.sims', 'fltFields' => ['type', 'sim', 'q'], 'fltKeep' => ['f' => $f, 'v' => $v], 'fltQHint' => 'SIM number, client, phone or WhatsApp'])
    @endunless

    @if ($v === 'cards')
        <div class="ds-legend"><span><i style="background:var(--ds-good)"></i>Paid</span><span><i style="background:var(--ds-mut)"></i>Unpaid (not due yet)</span><span><i style="background:var(--ds-warn)"></i>Due within 3 days</span><span><i style="background:var(--ds-bad)"></i>Overdue</span><span><span class="ds-rc" style="margin:0 4px 0 0">&#8635;2</span>Recharged this cycle</span></div>
        @if (! $shown)<div class="ds-card"><div class="ds-empty">{{ $q !== '' ? 'No SIM or client matches “'.$q.'”.' : ($cd['sims'] ? 'No SIM matches this filter.' : 'No SIMs yet. Press New SIM to add your first SIM card.') }}</div></div>@endif
        <div class="ds-sims">
        @foreach ($shown as $s)
            @php $info = $simInfo[$s['id']]; $pu = $s['pkg_until'] ?? null; $pn = $pu ? V::daysTo($pu) : null; @endphp
            <div class="ds-card ds-sim">
                <div class="ds-sim__h"><div><b>{{ $s['number'] }}</b><small>{{ $s['kind'] === 'full' ? 'Full SIM · 1 client' : 'Shared · 5 slots' }} &middot; {{ (int) $s['used'] }} of {{ (int) $s['gb'] }} GB given &middot; {{ max(0, (int) $s['spare']) }} GB spare</small></div><span class="ds-pill {{ $s['kind'] === 'full' ? '' : 'good' }}">{{ (int) $s['gb'] }} GB</span></div>
                <div class="ds-bar" title="{{ (int) $s['used'] }} of {{ (int) $s['gb'] }} GB given to clients"><i style="width:{{ min(100, round($s['used'] / max(1, $s['gb']) * 100)) }}%"></i></div>
                <div class="ds-pkg {{ $pu === null ? '' : ($pn < 0 ? 'gone' : ($pn <= 3 ? 'soon' : '')) }}"><span>@if ($pu)Package until <b>{{ $d($pu) }}</b>{{ $pn < 0 ? ' · ran out' : ($pn <= 3 ? ' · '.($pn === 0 ? 'today' : $pn.' day'.($pn === 1 ? '' : 's').' left') : '') }}@else No package date &middot; <a href="#" data-ds-editsim-open="{{ (int) $s['id'] }}">Set it</a>@endif</span></div>
                @if ($s['kind'] !== 'full')
                    @php $mcc = $s['contact'] ?? null; @endphp
                    @if ($mcc)
                        @php $mcMsg = V::mcMsg($s, $company); @endphp
                        <div class="ds-mc"><span><span class="ds-star">&#9733;</span>Main contact: <b>{{ $mcc['name'] }}</b>{{ $mcc['phone'] !== '' ? ' · '.$mcc['phone'] : '' }}{!! $mcc['client_id'] ? '' : ' <small>(not on this SIM)</small>' !!}@php $nd = count(V::mcDue($s)); @endphp @if ($nd) &middot; {{ $nd }} not paid @endif</span>@if ($mcMsg){{ V::waButton($mcMsg, $mcc['name'], $mcc['whatsapp'] !== '' ? $mcc['whatsapp'] : $mcc['phone'], 'Remind main contact') }}@endif</div>
                    @else
                        <div class="ds-mc ds-mc--none"><span>No main contact &middot; <a href="#" data-ds-editsim-open="{{ (int) $s['id'] }}">Choose one</a></span></div>
                    @endif
                @endif
                <div class="ds-slots">
                    @for ($k = 0; $k < $s['slots']; $k++)
                        @php $x = $s['clients'][$k] ?? null; @endphp
                        @if ($x)
                            @php $soon = $x['status'] === 'unpaid' && V::daysTo($x['next']) <= 3; $up = V::usagePct($x); @endphp
                            <a class="ds-slot {{ $soon ? 'soon' : $x['status'] }}" href="{{ route('datasharing.client', $x['id']) }}" title="{{ $up === null ? 'No data reading yet' : V::gbf($x['used']).' of '.(int) $x['used_of'].' GB used, read '.$d($x['read_on']) }}"><i class="d"></i><span>{{ $x['name'] }}{{ V::star($x) }}{{ V::rcMark($x) }}</span><em>{{ (int) $x['gb'] }} GB &middot; {{ $up === null ? $m($x['price']) : V::gbf($x['left']).' GB left' }}</em>@if ($up !== null)<i class="u" style="width:{{ $up }}%"></i>@endif</a>
                        @else
                            <button type="button" class="ds-slot free" data-ds-add="{{ (int) $s['id'] }}"><i class="d"></i><span>+ Add client to slot {{ $k + 1 }}</span><em>{{ max(0, (int) $s['spare']) }} GB spare</em></button>
                        @endif
                    @endfor
                </div>
                <div class="ds-sim__f"><span>{{ $m($s['income']) }} in &minus; {{ $m($s['cost']) }} cost</span><b class="{{ $s['profit'] < 0 ? 'neg' : '' }}">{{ $m($s['profit']) }}</b></div>
                <div class="ds-sim__a">
                    @if ($s['clients'])<button type="button" class="ds-btn ds-btn--sm" data-ds-usage="{{ (int) $s['id'] }}">Data used</button><button type="button" class="ds-btn ds-btn--sm" data-ds-move="{{ (int) $s['id'] }}">Move clients</button>@endif
                    <button type="button" class="ds-btn ds-btn--sm" data-ds-editsim="{{ (int) $s['id'] }}" data-number="{{ $s['number'] }}" data-cost="{{ number_format((float) $info['cost'], 2, '.', '') }}" data-method="{{ $info['method'] }}" data-empty="{{ $s['clients'] ? 0 : 1 }}" data-kind="{{ $s['kind'] }}" data-until="{{ $s['pkg_until'] ?? '' }}" data-contact="{{ ! empty($info['contact_client_id']) && ! empty($s['contact']['client_id']) ? (int) $s['contact']['client_id'] : (! empty($s['contact']) ? 'other' : '') }}" data-cname="{{ $info['contact_name'] ?? '' }}" data-cphone="{{ $info['contact_phone'] ?? '' }}" data-cwa="{{ $info['contact_whatsapp'] ?? '' }}">Edit SIM</button>
                </div>
            </div>
        @endforeach
        </div>
    @else
        <div class="ds-card"><div class="ds-tw">
            <table class="ds-table">
                <thead><tr><th class="r">Slot</th><th>Client</th><th>Phone</th><th>Plan</th><th class="r">Used</th><th class="r">Left</th><th>Status</th><th>Plan paid</th><th class="r">Recharges</th><th class="r">Paid this cycle</th><th class="r">Cost share</th><th class="r">Profit</th></tr></thead>
                <tbody>
                @foreach ($shown as $s)
                    <tr class="ds-group"><td colspan="12">{{ $s['number'] }} <small>&middot; {{ $s['kind'] === 'full' ? 'Full SIM' : 'Shared' }} &middot; {{ (int) $s['used'] }}/{{ (int) $s['gb'] }} GB &middot; {{ max(0, (int) $s['spare']) }} GB spare &middot; {{ $s['slots'] - $s['free_slots'] }} of {{ $s['slots'] }} slots &middot; package {{ $m($s['pkg']) }}@if (! empty($s['contact'])) &middot; main contact {{ $s['contact']['name'] }}{{ $s['contact']['phone'] !== '' ? ' ('.$s['contact']['phone'].')' : '' }}@endif</small></td></tr>
                    @foreach ($s['clients'] as $k => $x)
                        @if ($match($x) || $f === 'all')
                        <tr><td class="r">{{ $k + 1 }}</td><td><a href="{{ route('datasharing.client', $x['id']) }}"><b>{{ $x['name'] }}</b></a>{{ $exporting ? (! empty($x['main']) ? ' (main contact)' : '') : V::star($x) }}</td><td>{{ $x['phone'] }}</td><td>{{ (int) $x['gb'] }} GB</td><td class="r">{{ isset($x['used']) ? V::gbf($x['used']).' GB' : '–' }}</td><td class="r">{{ isset($x['left']) ? V::gbf($x['left']).' GB' : '–' }}</td>
                            <td>{{ $exporting ? V::STATUS[$x['status']] : V::pill($x) }}</td><td>{{ $x['plan_date'] ? $d($x['plan_date']).' · '.$x['plan_method'] : '–' }}</td>
                            <td class="r">{{ $x['rech_n'] ? $x['rech_n'].' · '.$m($x['rech_amt']) : '–' }}</td><td class="r">{{ $m($x['income']) }}</td><td class="r">{{ $m($x['share'] + $x['extra']) }}</td><td class="r {{ $x['profit'] < 0 ? 'bad' : '' }}"><b>{{ $m($x['profit']) }}</b></td></tr>
                        @endif
                    @endforeach
                    @if ($f === 'all' || $f === 'free')
                        @for ($k = count($s['clients']); $k < $s['slots']; $k++)
                            <tr class="ds-free"><td class="r">{{ $k + 1 }}</td><td colspan="11">Free slot &middot; {{ max(0, (int) $s['spare']) }} GB spare on this SIM @unless ($exporting)<button type="button" class="ds-btn ds-btn--sm" data-ds-add="{{ (int) $s['id'] }}">Add client</button>@endunless</td></tr>
                        @endfor
                    @endif
                @endforeach
                @if (! $shown)<tr><td colspan="12" class="ds-empty">No SIM matches this filter.</td></tr>@endif
                </tbody>
            </table>
        </div></div>
        <p class="ds-hint">Cost share = the SIM package split by the GB each client gets, plus any extra data bought for that client.</p>
    @endif
</section>

@unless ($exporting)
<dialog class="ds-modal" id="dsNewSim">
    <form method="POST" action="{{ route('datasharing.sim-add') }}">@csrf <input type="hidden" name="return" value="{{ $here }}">
        <h3>New SIM</h3>
        <div class="ds-form">
            <label>SIM number<input class="ds-field" name="number" placeholder="e.g. 083 555 0199" inputmode="tel" required></label>
            <fieldset class="ds-opts"><legend>Type</legend>
                <label class="is-on"><input type="radio" name="kind" value="shared" checked><div>Shared &middot; 5 slots<small class="ds-hint" style="display:block">The package is shared to up to 5 clients</small></div></label>
                <label><input type="radio" name="kind" value="full"><div>Full SIM &middot; 1 client<small class="ds-hint" style="display:block">One client gets the whole package</small></div></label>
            </fieldset>
            <div class="ds-row2"><label>Package GB<input class="ds-field" name="gb" value="200" inputmode="numeric" required></label><label>Package cost<input class="ds-field" name="cost" placeholder="0.00" inputmode="decimal" required></label></div>
            <label>Package paid from<select class="ds-field" name="method">{{ V::methodOptions('Bank') }}</select><small>Every new cycle the package is added to Money Out by itself, paid this way.</small></label>
            <label>Package runs out on<input class="ds-field" type="date" name="pkg_until" value="{{ $ds->addMonths(date('Y-m-d'), 1) }}"><small>Today shows a warning 3 days before it runs out</small></label>
            <label class="ds-check"><input type="checkbox" name="paid_now" value="1" checked> The package for this cycle is already bought &mdash; add it to Money Out now</label>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Add SIM</button></div>
    </form>
</dialog>

<dialog class="ds-modal" id="dsEditSim">
    <form method="POST" action="{{ route('datasharing.sim-save', 0) }}" id="dsEditSimForm">@csrf <input type="hidden" name="return" value="{{ $here }}">
        <h3>Edit SIM</h3>
        <div class="ds-form">
            <label>SIM number<input class="ds-field" name="number" required></label>
            <div class="ds-row2"><label>Package cost<input class="ds-field" name="cost" inputmode="decimal" required></label><label>Paid from<select class="ds-field" name="method">{{ V::methodOptions('Bank') }}</select></label></div>
            <label>Package runs out on<input class="ds-field" type="date" name="pkg_until"><small>Empty = not tracked</small></label>
            <p class="ds-hint">The new cost is used from the next cycle's package.</p>
            <div id="dsMcWrap"><input type="hidden" name="contact_set" value="1" id="dsMcSet">
                <label>Main contact<select class="ds-field" name="contact" id="dsMcPick"></select><small>The person responsible for the whole package. Each client still pays for their own slot; unpaid slots also show under him and he can get one reminder for all of them.</small></label>
                <div id="dsMcOther" hidden>
                    <label>Name<input class="ds-field" name="contact_name"></label>
                    <div class="ds-row2"><label>Phone<input class="ds-field" name="contact_phone" inputmode="tel"></label><label>WhatsApp number<input class="ds-field" name="contact_whatsapp" inputmode="tel" placeholder="Empty = same as phone"></label></div>
                </div>
            </div>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Save</button></div>
    </form>
    @if ($canManage)
    <form method="POST" action="{{ route('datasharing.sim-retire', 0) }}" id="dsRetireForm" data-confirm="Remove this SIM from the board? Its past numbers stay in the reports." class="ds-form" style="padding-top:0">@csrf <input type="hidden" name="return" value="{{ $here }}">
        <div id="dsRetireWrap"><button type="submit" class="ds-btn ds-btn--sm ds-btn--bad">Remove SIM from the board</button></div></form>
    @endif
</dialog>

<dialog class="ds-modal" id="dsAdd">
    <form method="POST" action="{{ route('datasharing.client-add') }}">@csrf <input type="hidden" name="return" value="{{ $here }}"><input type="hidden" name="sim_id" id="dsAddSim">
        <h3 id="dsAddT">Add client</h3>
        <div class="ds-form">
            <p class="ds-hint" id="dsAddInfo"></p>
            <label>Name<input class="ds-field" name="name" required></label>
            <div class="ds-row2"><label>Data number<input class="ds-field" name="phone" inputmode="tel" placeholder="The number the data goes to"></label><label>WhatsApp number<input class="ds-field" name="whatsapp" inputmode="tel" placeholder="Empty = same as data number"><small>Where payment reminders go</small></label></div>
            <div class="ds-row2"><label>Plan<select class="ds-field" name="gb" id="dsAddPlan"></select></label><label>Price every month<input class="ds-field" name="price" id="dsAddPrice" inputmode="decimal" required></label></div>
            <label>First payment due<input class="ds-field" type="date" name="next_due" value="{{ date('Y-m-d') }}" required></label>
            <div class="ds-alert" id="dsAddErr" hidden></div>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri" id="dsAddGo">Add client</button></div>
    </form>
</dialog>

<dialog class="ds-modal" id="dsUse">
    <form method="POST" action="{{ route('datasharing.usage-sim', 0) }}" id="dsUseForm">@csrf <input type="hidden" name="return" value="{{ $here }}">
        <h3 id="dsUseT">Data used</h3>
        <div class="ds-form">
            <p class="ds-hint">Check each number in MTN and type what it shows. Leave a box empty to skip that client.</p>
            <div class="ds-row2"><label>The numbers are<select class="ds-field" name="mode"><option value="used">GB used</option><option value="left">GB left</option></select></label><label>Checked on<input class="ds-field" type="date" name="date" value="{{ date('Y-m-d') }}" max="{{ date('Y-m-d') }}" required></label></div>
            <div class="ds-usage-rows" id="dsUseRows"></div>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Save readings</button></div>
    </form>
</dialog>

<dialog class="ds-modal" id="dsMove">
    <form method="POST" action="{{ route('datasharing.move') }}">@csrf <input type="hidden" name="return" value="{{ $here }}"><input type="hidden" name="from" id="dsMoveFrom">
        <h3 id="dsMoveT">Move clients</h3>
        <div class="ds-form">
            <p class="ds-hint">Tick the clients to move, then choose the number they go to.</p>
            <div class="ds-checks" id="dsMoveList"></div>
            <label>Move to<select class="ds-field" name="to" id="dsMoveTo"></select></label>
            <div class="ds-row2" id="dsMoveNew" hidden><label>New SIM number<input class="ds-field" name="new_number" inputmode="tel"></label><label>Package cost<input class="ds-field" name="new_cost" inputmode="decimal"></label></div>
            <div id="dsMoveNew2" hidden class="ds-row2"><label>Paid from<select class="ds-field" name="new_method">{{ V::methodOptions('Bank') }}</select></label><label class="ds-check"><input type="checkbox" name="new_paid_now" value="1" checked> Package bought &mdash; add to Money Out</label></div>
            <div class="ds-post" id="dsMovePost"></div>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Move</button></div>
    </form>
</dialog>

<script>
(function () {
    var plans = {{ \Illuminate\Support\Js::from($plansBy) }}, sims = {{ \Illuminate\Support\Js::from($moveData) }}, base = {{ \Illuminate\Support\Js::from(url('/datasharing')) }};
    var q = function (s, r) { return (r || document).querySelector(s); }, el = function (tag, props, kids) { var e = document.createElement(tag); Object.keys(props || {}).forEach(function (k) { if (k === 'text') { e.textContent = props[k]; } else { e.setAttribute(k, props[k]); } }); (kids || []).forEach(function (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); }); return e; };
    var clear = function (n) { while (n.firstChild) { n.removeChild(n.firstChild); } return n; };
    document.addEventListener('click', function (e) {
        var b;
        if ((b = e.target.closest('[data-ds-add]'))) { addClient(b.getAttribute('data-ds-add')); }
        else if ((b = e.target.closest('[data-ds-editsim]'))) { editSim(b); }
        else if ((b = e.target.closest('[data-ds-editsim-open]'))) { e.preventDefault(); var t = q('[data-ds-editsim="' + b.getAttribute('data-ds-editsim-open') + '"]'); if (t) { editSim(t); } }
        else if ((b = e.target.closest('[data-ds-usage]'))) { usage(b.getAttribute('data-ds-usage')); }
        else if ((b = e.target.closest('[data-ds-move]'))) { moveOpen(b.getAttribute('data-ds-move')); }
    });
    function addClient(id) {
        var s = sims[id], sel = clear(q('#dsAddPlan')), list = plans[s.kind] || [], pick = null;
        q('#dsAddSim').value = id; q('#dsAddT').textContent = 'Add client to ' + s.number; q('#dsAddInfo').textContent = Math.max(0, s.spare) + ' GB spare on this SIM';
        list.forEach(function (p) { var o = el('option', { value: p.gb, 'data-price': p.price, text: p.name }); if (p.gb > s.spare) { o.textContent = p.name + ' (not enough space)'; } else if (pick === null) { pick = p.gb; } sel.appendChild(o); });
        if (pick !== null) { sel.value = pick; }
        planChange(); dsOpen('dsAdd');
    }
    function planChange() {
        var sel = q('#dsAddPlan'), o = sel.options[sel.selectedIndex], s = sims[q('#dsAddSim').value], gb = +sel.value;
        q('#dsAddPrice').value = o ? o.getAttribute('data-price') : '';
        var bad = s && gb > s.spare; var er = q('#dsAddErr'); er.hidden = !bad; er.textContent = bad ? 'Only ' + Math.max(0, s.spare) + ' GB is spare on this SIM. Pick a smaller plan or another SIM.' : '';
        q('#dsAddGo').disabled = !!bad;
    }
    q('#dsAddPlan').addEventListener('change', planChange);
    function editSim(b) {
        var id = b.getAttribute('data-ds-editsim'), f = q('#dsEditSimForm');
        f.action = base + '/sim-save/' + id;
        f.number.value = b.getAttribute('data-number'); f.cost.value = b.getAttribute('data-cost'); f.method.value = b.getAttribute('data-method'); f.pkg_until.value = b.getAttribute('data-until') || '';
        var rf = q('#dsRetireForm'); if (rf) { rf.action = base + '/sim-retire/' + id; q('#dsRetireWrap').hidden = b.getAttribute('data-empty') !== '1'; }
        var shared = b.getAttribute('data-kind') !== 'full', pk = clear(q('#dsMcPick')), cur = b.getAttribute('data-contact') || '';
        q('#dsMcWrap').hidden = !shared; q('#dsMcSet').disabled = !shared;
        pk.appendChild(el('option', { value: '', text: 'No main contact' }));
        ((sims[id] || { clients: [] }).clients).forEach(function (c) { pk.appendChild(el('option', { value: c.id, text: c.name + ' · on this SIM' })); });
        pk.appendChild(el('option', { value: 'other', text: 'Someone not on this SIM…' }));
        pk.value = cur; if (pk.value !== cur) { pk.value = ''; }
        f.contact_name.value = b.getAttribute('data-cname') || ''; f.contact_phone.value = b.getAttribute('data-cphone') || ''; f.contact_whatsapp.value = b.getAttribute('data-cwa') || '';
        mcChange(); dsOpen('dsEditSim');
    }
    function mcChange() { var o = q('#dsMcPick').value === 'other'; q('#dsMcOther').hidden = !o; q('#dsMcOther [name=contact_name]').required = o; }
    q('#dsMcPick').addEventListener('change', mcChange);
    function usage(id) {
        var s = sims[id], box = clear(q('#dsUseRows'));
        q('#dsUseForm').action = base + '/usage-sim/' + id; q('#dsUseT').textContent = 'Data used on ' + s.number;
        s.clients.forEach(function (c) { box.appendChild(el('div', { 'class': 'r' }, [el('div', {}, [el('b', { text: c.name + ' · ' + c.gb + ' GB' }), el('small', { text: 'Last: ' + c.last })]), el('input', { 'class': 'ds-field', inputmode: 'decimal', placeholder: 'GB', autocomplete: 'off', name: 'u[' + c.id + ']' })])); });
        dsOpen('dsUse');
    }
    function moveUpd() {
        var to = q('#dsMoveTo').value, n = 0, gb = 0;
        document.querySelectorAll('#dsMoveList input:checked').forEach(function (i) { n++; gb += +i.getAttribute('data-gb'); });
        q('#dsMoveNew').hidden = to !== 'new'; q('#dsMoveNew2').hidden = to !== 'new';
        var room = to === 'new' ? { free: 5, spare: 200 } : sims[to];
        q('#dsMovePost').innerHTML = '<div><span>' + n + ' client' + (n === 1 ? '' : 's') + ' · ' + gb + ' GB</span><span>room: ' + (room ? room.free + ' slots · ' + Math.max(0, room.spare) + ' GB' : '') + '</span></div>';
    }
    function moveOpen(from) {
        var s = sims[from], list = clear(q('#dsMoveList')), to = clear(q('#dsMoveTo'));
        q('#dsMoveFrom').value = from; q('#dsMoveT').textContent = 'Move clients from ' + s.number;
        s.clients.forEach(function (c) { list.appendChild(el('label', {}, [el('input', { type: 'checkbox', name: 'clients[]', value: c.id, 'data-gb': c.gb }), c.name, el('span', { text: c.gb + ' GB' })])); });
        Object.keys(sims).forEach(function (id) { var o = sims[id]; if (id !== String(from) && o.free > 0) { to.appendChild(el('option', { value: id, text: o.number + ' · ' + o.free + ' free slot' + (o.free > 1 ? 's' : '') + ' · ' + Math.max(0, o.spare) + ' GB spare' })); } });
        to.appendChild(el('option', { value: 'new', text: 'A new SIM number…' }));
        moveUpd(); dsOpen('dsMove');
    }
    document.addEventListener('change', function (e) { if (e.target.closest && e.target.closest('#dsMoveList, #dsMoveTo')) { moveUpd(); } });
})();
</script>
@include('datasharing._modals')
@endunless
@endsection
