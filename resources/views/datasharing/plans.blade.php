@extends($exporting ? 'datasharing.bare' : 'layouts.main')
@section('title', 'Data Sharing - Plan settings')
@section('content')
@php
    use App\Support\DsView as V;
    $m = fn ($v) => V::m($v);
    $groups = ['shared' => [], 'full' => []];
    foreach ($plans as $p) { $groups[(int) $p['full'] ? 'full' : 'shared'][] = $p; }
    $titles = ['shared' => ['Slot plans', 'One slot on a shared SIM (up to 5 clients share the package)'], 'full' => ['Full SIM plans', 'One client gets the whole SIM']];
@endphp
@include('datasharing._style')
<section class="ds">
    <div class="ds-ph"><div><h1>Plan settings</h1><p>The plans you sell. A client keeps their own price; changing a plan changes it for new clients (and, if you tick it, for clients on the old price).</p></div>
        <div class="ds-tools"><a class="ds-btn" href="{{ route('datasharing.sims') }}">SIM board</a><button type="button" class="ds-btn ds-btn--pri" data-ds-plan="new">+ Add plan</button></div></div>
    @include('datasharing._top')
    @foreach ($groups as $k => $list)
    @php $cg = $cpg[$k] ?? null; @endphp
    <div class="ds-card"><div class="ds-card__h"><h2>{{ $titles[$k][0] }} <span class="ds-n">{{ count($list) }}</span></h2><span class="ds-hint">{{ $titles[$k][1] }}{{ $cg !== null ? ' · your '.($k === 'full' ? 'full' : 'shared').' SIMs cost '.$m($cg).' per GB' : '' }}</span></div>
        <div class="ds-tw"><table class="ds-table">
            <thead><tr><th>Plan</th><th class="r">GB</th><th class="r">Price</th><th class="r">Price per GB</th><th class="r">Profit per GB</th><th class="r">Profit per client</th><th class="r">Clients</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach ($list as $p) @php $n = count($on[$p['id']]); @endphp
                <tr><td><b>{{ $ds->planLabel($p) }}</b>@if ($p['notes'])<br><small class="ds-hint">{{ $p['notes'] }}</small>@endif</td>
                    <td class="r">{{ (int) $p['gb'] }}</td><td class="r">{{ $m($p['price']) }}</td><td class="r">{{ $m($p['price'] / max(1, (int) $p['gb'])) }}</td>
                    @if ($cg !== null)
                        @php $ppg = $p['price'] / max(1, (int) $p['gb']) - $cg; $ppc = (float) $p['price'] - (int) $p['gb'] * $cg; @endphp
                        <td class="r {{ $ppg < 0 ? 'bad' : 'good' }}">{{ $m($ppg) }}@if ($ppg < 0)<br><span class="ds-pill below">below cost</span>@endif</td><td class="r {{ $ppc < 0 ? 'bad' : '' }}">{{ $m($ppc) }}</td>
                    @else<td class="r">–</td><td class="r">–</td>@endif
                    <td class="r">{{ $n ?: '–' }}</td>
                    <td>@if ((int) $p['active'])<span class="ds-pill good">In use</span>@else<span class="ds-pill">Hidden</span>@endif</td>
                    <td class="r" style="white-space:nowrap">
                        <button type="button" class="ds-btn ds-btn--sm" data-ds-plan="{{ (int) $p['id'] }}" data-name="{{ $p['name'] }}" data-gb="{{ (int) $p['gb'] }}" data-price="{{ number_format((float) $p['price'], 2, '.', '') }}" data-full="{{ (int) $p['full'] }}" data-notes="{{ $p['notes'] }}" data-n="{{ $n }}">Edit</button>
                        @if ($n)<button type="button" class="ds-btn ds-btn--sm" data-ds-planmove="{{ (int) $p['id'] }}">Change plan for clients</button>@endif
                        <form method="POST" action="{{ route('datasharing.plan-toggle', $p['id']) }}" style="display:inline">@csrf <input type="hidden" name="return" value="{{ $here }}"><button type="submit" class="ds-btn ds-btn--sm">{{ (int) $p['active'] ? 'Hide' : 'Show' }}</button></form>
                        @if (! $n)<form method="POST" action="{{ route('datasharing.plan-delete', $p['id']) }}" style="display:inline" data-confirm="Delete the plan {{ $ds->planLabel($p) }}?">@csrf <input type="hidden" name="return" value="{{ $here }}"><button type="submit" class="ds-btn ds-btn--sm ds-btn--bad">Delete</button></form>@endif
                    </td></tr>
            @endforeach
            @if (! $list)<tr><td colspan="9" class="ds-empty">No plans yet.</td></tr>@endif
            </tbody>
        </table></div>
    </div>
    @endforeach
    <p class="ds-hint">Profit per GB = the plan's price per GB less what your SIM packages cost per GB (all your SIMs of that type together). It assumes the SIM's GB is all given out; GB nobody pays for lowers the real profit &mdash; see Profit. Red = the plan sells below cost.</p>
    <p class="ds-hint">Hidden plans can't be picked for new clients; clients already on them keep them. A plan's GB can only change while no client is on it &mdash; otherwise add a new plan and use "Change plan for clients".</p>
</section>

<dialog class="ds-modal" id="dsPlan">
    <form method="POST" action="{{ route('datasharing.plan-add') }}" id="dsPlanForm">@csrf <input type="hidden" name="return" value="{{ $here }}">
        <h3 id="dsPlanT">Add plan</h3>
        <div class="ds-form">
            <fieldset class="ds-opts"><legend>Plan for</legend>
                <label class="is-on"><input type="radio" name="full" value="0" checked><div>One slot on a shared SIM</div></label>
                <label><input type="radio" name="full" value="1"><div>A whole SIM (full package)</div></label>
            </fieldset>
            <div class="ds-row2"><label>GB<input class="ds-field" name="gb" inputmode="numeric" required placeholder="e.g. 60"></label><label>Price every month<input class="ds-field" name="price" inputmode="decimal" required placeholder="0.00"></label></div>
            <label>Name <small>(optional, e.g. Gold, Business)</small><input class="ds-field" name="name" maxlength="60"></label>
            <label>Note <small>(optional)</small><input class="ds-field" name="notes" maxlength="255" placeholder="e.g. for shops only"></label>
            <label id="dsPlanApply" class="ds-check" hidden><input type="checkbox" name="apply" value="1" checked> Also give the new price to clients on this plan who pay the old price</label>
            <p class="ds-hint" id="dsPlanLock" hidden>Clients are on this plan, so its GB and type can't change.</p>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Save plan</button></div>
    </form>
</dialog>
<dialog class="ds-modal" id="dsPlanMove">
    <form method="POST" action="{{ route('datasharing.plan-move') }}">@csrf <input type="hidden" name="return" value="{{ $here }}"><input type="hidden" name="from" id="dsPmFrom">
        <h3 id="dsPlanMoveT">Change plan for clients</h3>
        <div class="ds-form">
            <p class="ds-hint">Tick the clients, then choose their new plan. Their GB and price become the new plan's; a client whose SIM hasn't enough spare GB is left as is. A &ldquo;To do in MTN&rdquo; note is made for each change.</p>
            <div class="ds-checks" id="dsPmList"></div>
            <label>New plan<select class="ds-field" name="to" id="dsPmTo"></select></label>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Change plan</button></div>
    </form>
</dialog>
@include('datasharing._modals')
<script>
(function () {
    var base = {{ \Illuminate\Support\Js::from(url('/datasharing')) }};
    var plans = {{ \Illuminate\Support\Js::from(array_map(fn ($p) => ['id' => (int) $p['id'], 'label' => $ds->planLabel($p), 'full' => (int) $p['full'], 'gb' => (int) $p['gb'], 'active' => (int) $p['active']], $plans)) }};
    var on = {{ \Illuminate\Support\Js::from(array_map(fn ($list) => array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'phone' => $c['phone']], $list), $on)) }};
    var q = function (s) { return document.querySelector(s); }, f = q('#dsPlanForm');
    var clear = function (n) { while (n.firstChild) { n.removeChild(n.firstChild); } return n; };
    var opts = function () { f.querySelectorAll('.ds-opts label').forEach(function (l) { l.classList.toggle('is-on', l.querySelector('input').checked); }); };
    f.addEventListener('change', opts);
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-ds-plan]');
        if (b) {
            var id = b.getAttribute('data-ds-plan'), isNew = id === 'new', n = +(b.getAttribute('data-n') || 0);
            f.action = base + (isNew ? '/plan-add' : '/plan-save/' + id);
            q('#dsPlanT').textContent = isNew ? 'Add plan' : 'Change plan';
            f.name.value = isNew ? '' : b.getAttribute('data-name'); f.gb.value = isNew ? '' : b.getAttribute('data-gb'); f.gb.readOnly = !isNew && n > 0;
            f.price.value = isNew ? '' : b.getAttribute('data-price'); f.notes.value = isNew ? '' : b.getAttribute('data-notes');
            var full = isNew ? '0' : b.getAttribute('data-full');
            f.querySelectorAll('[name=full]').forEach(function (r) { r.checked = r.value === full; r.disabled = !isNew && n > 0 && r.value !== full; });
            q('#dsPlanApply').hidden = isNew || n === 0; q('#dsPlanLock').hidden = isNew || n === 0;
            opts(); dsOpen('dsPlan'); return;
        }
        b = e.target.closest('[data-ds-planmove]');
        if (b) {
            var pid = +b.getAttribute('data-ds-planmove'), from = plans.filter(function (p) { return p.id === pid; })[0];
            q('#dsPmFrom').value = pid; q('#dsPlanMoveT').textContent = 'Change plan for clients on ' + from.label;
            var list = clear(q('#dsPmList'));
            (on[pid] || []).forEach(function (c) { var l = document.createElement('label'), i = document.createElement('input'); i.type = 'checkbox'; i.name = 'clients[]'; i.value = c.id; i.checked = true; var s = document.createElement('span'); s.textContent = c.phone || ''; l.appendChild(i); l.appendChild(document.createTextNode(c.name)); l.appendChild(s); list.appendChild(l); });
            var to = clear(q('#dsPmTo'));
            plans.forEach(function (p) { if (p.full === from.full && p.id !== pid) { var o = document.createElement('option'); o.value = p.id; o.textContent = p.label + (p.gb > from.gb ? ' - upgrade' : ' - downgrade') + (p.active ? '' : ' (hidden)'); to.appendChild(o); } });
            dsOpen('dsPlanMove');
        }
    });
})();
</script>
@endsection
