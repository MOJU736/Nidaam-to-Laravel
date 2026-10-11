@extends($exporting ? 'datasharing.bare' : 'layouts.main')
@section('title', 'Data Sharing - '.$c['name'])
@section('content')
@php
    use App\Support\DsView as V;
    $m = fn ($v) => V::m($v); $d = fn ($v) => V::d($v);
    $x = $row ?: ['id' => (int) $c['id'], 'name' => $c['name'], 'phone' => $c['phone'], 'gb' => (int) $c['gb'], 'price' => (float) $c['price'], 'next' => $c['next_due'], 'status' => 'unpaid', 'sim' => $s['number'], 'rech_n' => 0, 'income' => 0, 'share' => 0, 'extra' => 0, 'profit' => 0, 'credit' => 0, 'owed' => $c['price']];
    $rechN = $x['rech_n'];
    $canSpare = $spare >= (int) $c['gb'];
    $okDests = array_values(array_filter($dests, fn ($o) => $o['free'] > 0 && $o['spare'] >= (int) $c['gb']));
    $moveDests = array_values(array_filter($dests, fn ($o) => $o['free'] > 0));
    $up = $rechN >= 2 ? V::upgradeFor($x, $s['kind']) : null;
    $upct = V::usagePct($x);
    $sym = preg_replace('/[0-9.,]+$/', '', V::m(0));
@endphp
@include('datasharing._style')
<section class="ds">
    <div class="ds-ph"><div><h1>{{ $c['name'] }} {{ V::star($x) }}{{ V::rcMark($x) }}</h1><p>Data {{ $c['phone'] }}{{ ! empty($c['whatsapp']) && $c['whatsapp'] !== $c['phone'] ? ' · WhatsApp '.$c['whatsapp'] : '' }} &middot; {{ (int) $c['gb'] }} GB on {{ $s['number'] }} &middot; {{ $m($c['price']) }} every month (pays on the {{ V::ordinal($ds->dueDay($c)) }})</p>
        @if (! empty($contact))<p><span class="ds-star" style="margin:0 4px 0 0">&#9733;</span>@if ((int) $contact['client_id'] === (int) $c['id'])Main contact of {{ $s['number'] }} &mdash; responsible for the whole package @else Main contact of {{ $s['number'] }}: <b>{{ $contact['name'] }}</b>{{ $contact['phone'] !== '' ? ' · '.$contact['phone'] : '' }}@endif</p>@endif</div>
        <div class="ds-tools">
            {{ V::payButton($x) }}
            <button type="button" class="ds-btn" id="dsRcBtn">Recharge</button>
            {{ V::waButton(V::waMsg($x, $company), $x['name'], V::wa($x + ['whatsapp' => $c['whatsapp'] ?? ''])) }}
            <button type="button" class="ds-btn" data-open="dsEdit">Edit</button>
            <button type="button" class="ds-btn" data-open="dsMv">Move</button>
            <form method="POST" action="{{ route('datasharing.client-remove', $c['id']) }}" style="display:inline" data-confirm="Remove {{ $c['name'] }}? Their slot becomes free. Their payments stay in the books.">@csrf <button type="submit" class="ds-btn ds-btn--bad">Remove</button></form>
        </div></div>
    @include('datasharing._top')
    @if ($up)
        <div class="ds-note" role="note"><div><b>Suggest a bigger plan:</b> {{ $c['name'] }} recharged {{ (int) $rechN }} times this month and paid {{ $m($x['income']) }}. The {{ $ds->planLabel($up) }} plan is {{ $m($up['price']) }} a month.</div>
            <div style="display:flex;gap:6px">{{ V::waButton(V::upMsg($x, $up, $company), $x['name'], V::wa($x), 'Offer', 'WhatsApp offer') }}<button type="button" class="ds-btn ds-btn--sm" data-open="dsEdit">Change plan</button></div></div>
    @endif
    <div class="ds-info">
        <div><small>Status</small>{{ V::pill($x) }}</div>
        <div><small>Next payment due</small><b>{{ $d($c['next_due']) }}</b>@if ((float) $c['credit'] > 0)<span class="ds-hint">{{ $m($c['credit']) }} paid &middot; owes {{ $m($ds->owed($c)) }}</span>@endif</div>
        <div><small>Paid in cycle {{ (int) $cyc['no'] }}</small><b>{{ $m($x['income']) }}</b></div>
        <div><small>Profit from this client</small><b>{{ $m($x['profit']) }}</b></div>
        <div><small>Data used</small><b>{{ V::usageTxt($x) }}</b>@if (isset($x['used']) && $x['used'] !== null)<span class="ds-hint">of {{ (int) $x['used_of'] }} GB &middot; read {{ $d($x['read_on']) }}</span>@endif</div>
    </div>

    <div class="ds-card"><div class="ds-card__h"><h2>Data used</h2><span class="ds-hint">Check {{ $c['phone'] }} in MTN and type what it shows</span></div>
        <div class="ds-form">
            @if ($upct !== null)
                <div class="ds-use"><div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap"><b>{{ V::usageTxt($x) }}</b><span class="ds-hint">of {{ (int) $x['used_of'] }} GB &middot; read {{ $d($x['read_on']) }}</span></div><div class="ds-use__bar"><i style="width:{{ $upct }}%"></i></div></div>
            @else
                <p class="ds-hint" style="margin:0">No reading since {{ $c['name'] }}'s last payment or recharge.</p>
            @endif
            <form method="POST" action="{{ route('datasharing.usage-add', $c['id']) }}" class="ds-row2" style="align-items:end">@csrf <input type="hidden" name="return" value="{{ $here }}">
                <div class="ds-row2"><label>GB<input class="ds-field" name="value" inputmode="decimal" placeholder="e.g. 12.5" autocomplete="off" required></label><label>is<select class="ds-field" name="mode"><option value="used">used</option><option value="left">left</option></select></label></div>
                <div class="ds-row2" style="align-items:end"><label>Checked on<input class="ds-field" type="date" name="date" value="{{ date('Y-m-d') }}" max="{{ date('Y-m-d') }}" required></label><div><button type="submit" class="ds-btn ds-btn--pri">Save reading</button></div></div>
            </form>
        </div>
        @if ($usage)
        <div class="ds-tw"><table class="ds-table"><thead><tr><th>Checked on</th><th class="r">Used</th><th class="r">Left</th><th class="r">Plan</th><th>By</th>@if ($canManage)<th></th>@endif</tr></thead><tbody>
            @foreach ($usage as $u)<tr><td>{{ $d($u['date']) }}</td><td class="r">{{ V::gbf($u['used']) }} GB</td><td class="r">{{ V::gbf(max(0, $u['of_gb'] - $u['used'])) }} GB</td><td class="r">{{ (int) $u['of_gb'] }} GB</td><td>{{ $u['created_by'] }}</td>
                @if ($canManage)<td class="r"><form method="POST" action="{{ route('datasharing.usage-delete', $u['id']) }}" data-confirm="Delete this reading?">@csrf <input type="hidden" name="return" value="{{ $here }}"><button type="submit" class="ds-btn ds-btn--sm ds-btn--bad">Delete</button></form></td>@endif</tr>@endforeach
        </tbody></table></div>
        @endif
    </div>

    <div class="ds-card" id="dsRc" hidden>
        <div class="ds-card__h"><h2>Recharge &middot; second payment</h2><span class="ds-hint">Recharge {{ $rechN + 1 }} in cycle {{ (int) $cyc['no'] }}</span></div>
        <form method="POST" action="{{ route('datasharing.recharge', $c['id']) }}" class="ds-form" id="dsRcForm">@csrf <input type="hidden" name="return" value="{{ $here }}">
            <p class="ds-hint">{{ $c['name'] }} finished the {{ (int) $c['gb'] }} GB before the month ended and pays again. Their due date stays {{ $d($c['next_due']) }}.</p>
            <div class="ds-row2">
                <label>Amount<input class="ds-field" name="amount" value="{{ number_format((float) $c['price'], 2, '.', '') }}" inputmode="decimal" required><small>Same price as the {{ (int) $c['gb'] }} GB plan</small></label>
                <label>Paid by<select class="ds-field" name="method">{{ V::methodOptions('Cash') }}</select></label>
            </div>
            <label style="max-width:260px">Date<input class="ds-field" type="date" name="date" value="{{ date('Y-m-d') }}" min="{{ $cyc['start'] }}" max="{{ date('Y-m-d') }}" required></label>
            <fieldset class="ds-opts"><legend>Where does the new {{ (int) $c['gb'] }} GB come from?</legend>
                <label class="{{ $canSpare ? 'is-on' : 'off' }}"><input type="radio" name="src" value="spare" @checked($canSpare) @disabled(! $canSpare)><div>Spare data on {{ $s['number'] }}<small class="ds-hint" style="display:block">{{ max(0, $spare) }} GB spare{{ $canSpare ? ' · no extra cost' : ' · not enough' }}</small></div></label>
                <label class="{{ $canSpare ? '' : 'is-on' }}"><input type="radio" name="src" value="bought" @checked(! $canSpare)><div>Buy extra data on {{ $s['number'] }}<small class="ds-hint" style="display:block">Goes to Money Out as Extra data, charged to this client</small></div></label>
                <label class="{{ $okDests ? '' : 'off' }}"><input type="radio" name="src" value="moved" @disabled(! $okDests)><div>Move to a slot on another SIM<small class="ds-hint" style="display:block">{{ $okDests ? count($okDests).' SIM'.(count($okDests) > 1 ? 's have' : ' has').' a free slot and '.(int) $c['gb'].' GB spare' : 'No SIM has a free slot with '.(int) $c['gb'].' GB spare' }}</small></div></label>
            </fieldset>
            <div class="ds-row2" data-src="bought" {{ $canSpare ? 'hidden' : '' }}><label>Extra data cost<input class="ds-field" name="cost" placeholder="0.00" inputmode="decimal"></label><label>Paid from<select class="ds-field" name="cost_method">{{ V::methodOptions('Bank') }}</select></label></div>
            <label data-src="moved" hidden>Move to<select class="ds-field" name="to">@foreach ($okDests as $o)<option value="{{ (int) $o['id'] }}">{{ $o['number'] }} &middot; {{ (int) $o['free'] }} free slot{{ $o['free'] > 1 ? 's' : '' }} &middot; {{ (int) $o['spare'] }} GB spare</option>@endforeach</select></label>
            <label class="ds-check"><input type="checkbox" name="receipt" value="1" data-ds-receipt> Open a WhatsApp receipt after saving</label>
            <div><button type="submit" class="ds-btn ds-btn--pri">Save recharge</button></div>
        </form>
    </div>

    <div class="ds-card"><div class="ds-card__h"><h2>Cycle {{ (int) $cyc['no'] }} payments</h2><span class="ds-hint">Plan payment and recharges together</span></div><div class="ds-tw">
        <table class="ds-table">
            <thead><tr><th>Date</th><th>Type</th><th class="r">GB</th><th>Method</th><th>Data came from</th><th class="r">Amount</th>@if ($canManage)<th></th>@endif</tr></thead>
            <tbody>
            @php $n = 0; @endphp
            @foreach ($pays as $p)
                @php if ($p['kind'] === 'recharge') { $n++; } $rc = $ds->receipt($p['id'], $company, $sym); @endphp
                <tr><td>{{ $d($p['date']) }}</td><td>@if ($p['kind'] === 'plan'){!! (int) $p['months'] === 0 ? '<span class="ds-pill warn">Part payment</span>' : 'Plan payment'.((int) $p['months'] > 1 ? ' ×'.(int) $p['months'] : '') !!}@else<span class="ds-pill">Recharge {{ $n }}</span>@endif</td><td class="r">{{ (int) $p['gb'] }}</td><td>{{ $p['method'] }}</td>
                    <td>{{ $p['kind'] === 'recharge' ? ($p['note'] ?: ($p['src'] === 'bought' ? 'Bought extra data' : 'Spare GB on SIM')) : '' }}</td><td class="r">{{ $m($p['amount']) }}</td>
                    @if ($canManage)<td class="r">@if ($rc){{ V::waButton($rc['msg'], $rc['name'], (string) $rc['phone'], 'Receipt', 'WhatsApp receipt') }}@endif
                        <form method="POST" action="{{ route('datasharing.payment-void', $p['id']) }}" data-confirm="Void this payment? Its entry is removed from the books.">@csrf <input type="hidden" name="return" value="{{ $here }}"><button type="submit" class="ds-btn ds-btn--sm ds-btn--bad">Void</button></form></td>@endif</tr>
            @endforeach
            @if (! $pays)<tr><td colspan="7" class="ds-empty">Nothing paid yet this cycle.</td></tr>@endif
            </tbody>
            <tfoot>
                <tr><td colspan="5">Paid this cycle</td><td class="r">{{ $m($x['income']) }}</td>@if ($canManage)<td></td>@endif</tr>
                <tr><td colspan="5">Cost share ({{ (int) $c['gb'] }}/{{ (int) $s['gb'] }} GB of the package){{ $x['extra'] ? ' + extra data' : '' }}</td><td class="r">{{ $m($x['share'] + $x['extra']) }}</td>@if ($canManage)<td></td>@endif</tr>
                <tr><td colspan="5">Profit from this client</td><td class="r">{{ $m($x['profit']) }}</td>@if ($canManage)<td></td>@endif</tr>
            </tfoot>
        </table>
    </div></div>

    @php $older = array_filter($history, fn ($p) => (int) $p['cycle_id'] !== (int) $cyc['id']); @endphp
    @if ($older)
    <div class="ds-card"><div class="ds-card__h"><h2>Earlier payments</h2></div><div class="ds-tw">
        <table class="ds-table"><thead><tr><th>Date</th><th>Type</th><th>Method</th><th class="r">Amount</th></tr></thead><tbody>
        @foreach ($older as $p)<tr><td>{{ $d($p['date']) }}</td><td>{{ $p['kind'] === 'plan' ? 'Plan payment' : 'Recharge' }}</td><td>{{ $p['method'] }}</td><td class="r">{{ $m($p['amount']) }}</td></tr>@endforeach
        </tbody></table>
    </div></div>
    @endif
</section>

<dialog class="ds-modal" id="dsEdit">
    <form method="POST" action="{{ route('datasharing.client-save', $c['id']) }}">@csrf <input type="hidden" name="return" value="{{ $here }}">
        <h3>Edit {{ $c['name'] }}</h3>
        <div class="ds-form">
            <label>Name<input class="ds-field" name="name" value="{{ $c['name'] }}" required></label>
            <div class="ds-row2"><label>Data number<input class="ds-field" name="phone" value="{{ $c['phone'] }}" inputmode="tel"><small>The number the data is shared to</small></label><label>WhatsApp number<input class="ds-field" name="whatsapp" value="{{ $c['whatsapp'] ?? '' }}" inputmode="tel" placeholder="Empty = same as data number"><small>Where payment reminders go</small></label></div>
            <div class="ds-row2">
                <label>Plan<select class="ds-field" name="gb" id="dsEditPlan">@foreach ($plans as $p)<option value="{{ (int) $p['gb'] }}" data-price="{{ number_format((float) $p['price'], 2, '.', '') }}" @selected((int) $p['gb'] === (int) $c['gb'])>{{ $ds->planLabel($p) }} &middot; {{ $m($p['price']) }}{{ (int) $p['active'] ? '' : ' (hidden)' }}</option>@endforeach</select><small>{{ max(0, $spare) }} GB spare on {{ $s['number'] }}</small></label>
                <label>Price every month<input class="ds-field" name="price" id="dsEditPrice" value="{{ number_format((float) $c['price'], 2, '.', '') }}" inputmode="decimal" required><small>A special price for this client is fine</small></label>
            </div>
            <label style="max-width:260px">Next payment due<input class="ds-field" type="date" name="next_due" value="{{ $c['next_due'] }}" required></label>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Save</button></div>
    </form>
</dialog>
<dialog class="ds-modal" id="dsMv">
    <form method="POST" action="{{ route('datasharing.move') }}">@csrf <input type="hidden" name="return" value="{{ $here }}"><input type="hidden" name="from" value="{{ (int) $s['id'] }}"><input type="hidden" name="clients[]" value="{{ (int) $c['id'] }}">
        <h3>Move {{ $c['name'] }}</h3>
        <div class="ds-form">
            <p class="ds-hint">From {{ $s['number'] }}. To move several clients at once, use Move clients on the SIM board.</p>
            <label>Move to<select class="ds-field" name="to" id="dsMvTo">@foreach ($moveDests as $o)<option value="{{ (int) $o['id'] }}">{{ $o['number'] }} &middot; {{ (int) $o['free'] }} free slot{{ $o['free'] > 1 ? 's' : '' }} &middot; {{ max(0, (int) $o['spare']) }} GB spare</option>@endforeach<option value="new">A new SIM number…</option></select></label>
            <div class="ds-row2" id="dsMvNew" hidden><label>New SIM number<input class="ds-field" name="new_number" inputmode="tel"></label><label>Package cost<input class="ds-field" name="new_cost" inputmode="decimal"></label></div>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Move</button></div>
    </form>
</dialog>
@include('datasharing._modals')
<script>
(function () {
    var q = function (s) { return document.querySelector(s); };
    var src = function () {
        var c = q('#dsRcForm input[name=src]:checked'), v = c ? c.value : '';
        document.querySelectorAll('#dsRcForm [data-src]').forEach(function (e) { e.hidden = e.getAttribute('data-src') !== v; });
        document.querySelectorAll('#dsRcForm .ds-opts label').forEach(function (l) { l.classList.toggle('is-on', !!c && l.contains(c)); });
    };
    q('#dsRcForm').addEventListener('change', function (e) { if (e.target.name === 'src') { src(); } }); src();
    q('#dsRcBtn').addEventListener('click', function () { var r = q('#dsRc'); r.hidden = !r.hidden; if (!r.hidden) { r.scrollIntoView({ behavior: 'smooth' }); } });
    if (location.hash === '#recharge') { q('#dsRc').hidden = false; }
    q('#dsEditPlan').addEventListener('change', function () { q('#dsEditPrice').value = this.options[this.selectedIndex].getAttribute('data-price'); });
    q('#dsMvTo').addEventListener('change', function () { q('#dsMvNew').hidden = this.value !== 'new'; }); q('#dsMvTo').dispatchEvent(new Event('change'));
})();
</script>
@endsection
