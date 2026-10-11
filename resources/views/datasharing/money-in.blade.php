@extends($exporting ? 'datasharing.bare' : 'layouts.main')
@section('title', 'Data Sharing - Money In')
@section('content')
@php
    use App\Support\DsView as V;
    $m = fn ($v) => V::m($v); $d = fn ($v) => V::d($v);
    $rows = [];
    foreach ($rd['pay_rows'] as $p) { $rows[] = ['kind' => 'pay', 'id' => $p['id'], 'cycle_id' => $p['cycle_id'], 'date' => $p['date'], 'from' => $p['client'], 'client_id' => $p['client_id'], 'type' => $p['type'], 'sim' => $p['sim'], 'method' => $p['method'], 'text' => $p['src'], 'amount' => $p['amount']]; }
    foreach ($rd['in_rows'] as $p) { $rows[] = ['kind' => 'other', 'id' => $p['id'], 'cycle_id' => $p['cycle_id'], 'date' => $p['date'], 'from' => '', 'client_id' => 0, 'type' => $p['cat'], 'sim' => '', 'method' => $p['method'], 'text' => $p['text'] !== $p['cat'] ? $p['text'] : '', 'amount' => $p['amount']]; }
    usort($rows, fn ($a, $b) => $a['date'] === $b['date'] ? ($b['id'] <=> $a['id']) : strcmp($b['date'], $a['date']));
    $total = $rd['income'] + $rd['other_in'] + $rd['capital_in'];
    $qs = array_filter(['cycle' => request()->query('cycle'), 'from' => request()->query('from'), 'to' => request()->query('to'), 'kind' => request()->query('kind'), 'method' => request()->query('method'), 'sim' => request()->query('sim'), 'q' => request()->query('q')]);
@endphp
@include('datasharing._style')
<section class="ds">
    @unless ($exporting)
    <div class="ds-ph"><div><h1>Money In</h1><p>{{ $fv['subtitle'] }} &middot; client payments, recharges and other money received</p></div>
        <div class="ds-tools">@include('datasharing._export', ['route' => 'datasharing.money-in', 'qs' => $qs])<button type="button" class="ds-btn ds-btn--pri" data-open="dsIn">+ Record money in</button></div></div>
    @include('datasharing._top')
    @include('datasharing._filters', ['fltAction' => 'datasharing.money-in', 'fltFields' => ['period', 'kind', 'method', 'sim', 'q'], 'fltKeep' => [], 'fltKindOther' => true, 'fltQHint' => 'Client, number or details'])
    <div class="ds-kpis">
        <div class="ds-kpi good"><small>Money in</small><strong>{{ $m($total) }}</strong><span>{{ count($rows) }} payments</span></div>
        <div class="ds-kpi"><small>Plan payments</small><strong>{{ $m($rd['plan_in']) }}</strong><span>from clients</span></div>
        <div class="ds-kpi"><small>Recharges</small><strong>{{ $m($rd['rech_in']) }}</strong><span>second payments</span></div>
        <div class="ds-kpi"><small>Other money in</small><strong>{{ $m($rd['other_in'] + $rd['capital_in']) }}</strong><span>{{ $m($rd['other_in']) }} income &middot; {{ $m($rd['capital_in']) }} owner / loans</span></div>
    </div>
    @endunless
    <div class="ds-card"><div class="ds-tw">
        <table class="ds-table">
            <thead><tr><th>Date</th><th>From</th><th>Type</th><th>SIM</th><th>Method</th><th>Details</th><th class="r">Amount</th>@if ($canManage && ! $exporting)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($rows as $x)
                <tr><td>{{ $d($x['date']) }}</td>
                    <td>@if ($x['kind'] === 'pay' && ! $exporting)<a href="{{ route('datasharing.client', $x['client_id']) }}">{{ $x['from'] }}</a>@else{{ $x['from'] !== '' ? $x['from'] : '–' }}@endif</td>
                    <td>@if ($exporting){{ $x['type'] }}@else<span class="ds-pill {{ $x['kind'] === 'other' ? 'warn' : ($x['type'] === 'Plan payment' ? 'good' : '') }}">{{ $x['type'] }}</span>@endif</td>
                    <td>{{ $x['sim'] }}</td><td>{{ $x['method'] }}</td><td>{{ $x['text'] }}</td><td class="r">{{ $m($x['amount']) }}</td>
                    @if ($canManage && ! $exporting)<td class="r">@if ((int) $x['cycle_id'] === (int) $cyc['id'])<form method="POST" action="{{ route($x['kind'] === 'pay' ? 'datasharing.payment-void' : 'datasharing.income-void', $x['id']) }}" data-confirm="Void this money in? Its entry is removed from the books.">@csrf <input type="hidden" name="return" value="{{ $here }}"><button type="submit" class="ds-btn ds-btn--sm ds-btn--bad">Void</button></form>@endif</td>@endif</tr>
            @endforeach
            @if (! $rows)<tr><td colspan="8" class="ds-empty">No money in found.</td></tr>@endif
            </tbody>
            <tfoot><tr><td colspan="6">Total</td><td class="r">{{ $m($total) }}</td>@if ($canManage && ! $exporting)<td></td>@endif</tr></tfoot>
        </table>
    </div></div>
    @unless ($exporting)<p class="ds-hint">Owner money and loans are money in but not income, so they show here and under "By payment method" in Reports, not in Profit &amp; Loss.</p>@endunless
</section>
@unless ($exporting)
<dialog class="ds-modal" id="dsIn">
    <h3>Record money in</h3>
    <div class="ds-form" style="padding-bottom:0">
        <fieldset class="ds-opts"><legend>Who is it from?</legend>
            <label class="is-on"><input type="radio" name="in_kind" value="client" checked><div>A client &mdash; plan payment<small class="ds-hint" style="display:block">Each full month paid moves their due date on one month. For a recharge, open the client and press Recharge.</small></div></label>
            <label><input type="radio" name="in_kind" value="other"><div>Other money in<small class="ds-hint" style="display:block">Other income, SIM card sales, owner&rsquo;s money or a loan</small></div></label>
        </fieldset>
    </div>
    <form method="POST" action="{{ route('datasharing.pay', 0) }}" id="dsInClient">@csrf <input type="hidden" name="return" value="{{ $here }}">
        <div class="ds-form">
            <label>Client<select class="ds-field" id="dsInWho" required><option value="">Choose a client&hellip;</option>@foreach ($clientsAll as $c)<option value="{{ (int) $c['id'] }}" data-price="{{ number_format($ds->owed($c) > 0 ? $ds->owed($c) : (float) $c['price'], 2, '.', '') }}">{{ $c['name'] }} &middot; {{ $c['phone'] }}{{ isset($simNames[$c['sim_id']]) ? ' · '.$simNames[$c['sim_id']] : '' }}</option>@endforeach</select></label>
            <div class="ds-row2"><label>Amount<input class="ds-field" name="amount" id="dsInAmt" inputmode="decimal" required></label><label>Paid by<select class="ds-field" name="method">{{ V::methodOptions('Cash') }}</select></label></div>
            <div class="ds-row2"><label>Date<input class="ds-field" type="date" name="date" value="{{ date('Y-m-d') }}" min="{{ $cyc['start'] }}" max="{{ date('Y-m-d') }}" required></label><div></div></div>
            <p class="ds-hint" style="margin:0">Less than the price is a part payment (the client still owes the rest); more pays ahead.</p>
            <label class="ds-check"><input type="checkbox" name="receipt" value="1" data-ds-receipt> Open a WhatsApp receipt after saving</label>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Save payment</button></div>
    </form>
    <form method="POST" action="{{ route('datasharing.income-add') }}" id="dsInOther" hidden>@csrf <input type="hidden" name="return" value="{{ $here }}">
        <div class="ds-form">
            <div class="ds-row2"><label>For<select class="ds-field" name="cat">@foreach (array_keys(\App\Services\DataSharing\DataSharingService::CATS_IN) as $k)<option>{{ $k }}</option>@endforeach</select></label><label>Amount<input class="ds-field" name="amount" placeholder="0.00" inputmode="decimal" required></label></div>
            <label>Details <small>(optional)</small><input class="ds-field" name="text" placeholder="e.g. 2 SIM cards sold"></label>
            <div class="ds-row2"><label>Received by<select class="ds-field" name="method">{{ V::methodOptions('Cash') }}</select></label><label>Date<input class="ds-field" type="date" name="date" value="{{ date('Y-m-d') }}" min="{{ $cyc['start'] }}" max="{{ date('Y-m-d') }}" required></label></div>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Save</button></div>
    </form>
</dialog>
<script>
(function () {
    var base = {{ \Illuminate\Support\Js::from(url('/datasharing/pay')) }};
    var cf = document.getElementById('dsInClient'), of = document.getElementById('dsInOther'), who = document.getElementById('dsInWho');
    var sync = function () { var c = document.querySelector('#dsIn input[name=in_kind]:checked'), other = c && c.value === 'other'; cf.hidden = other; of.hidden = !other; };
    document.querySelector('#dsIn .ds-opts').addEventListener('change', sync);
    who.addEventListener('change', function () { var o = who.options[who.selectedIndex]; cf.action = base + '/' + (who.value || '0'); if (o && o.getAttribute('data-price')) { document.getElementById('dsInAmt').value = o.getAttribute('data-price'); } });
})();
</script>
@include('datasharing._modals')
@endunless
@endsection
