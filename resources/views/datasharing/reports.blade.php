@extends($exporting ? 'datasharing.bare' : 'layouts.main')
@section('title', 'Data Sharing - Reports')
@section('content')
@php
    use App\Support\DsView as V;
    $m = fn ($v) => V::m($v); $d = fn ($v) => V::d($v);
    $cats = array_keys(\App\Services\DataSharing\DataSharingService::CATS);
    $canClose = date('Y-m-d') > $cyc['end'];
    $firstCycle = ! $closed;
    $qs = array_filter(['cycle' => request()->query('cycle'), 'from' => request()->query('from'), 'to' => request()->query('to'), 'sim' => request()->query('sim'), 'method' => request()->query('method'), 'kind' => request()->query('kind'), 'q' => request()->query('q')]);
    $deckQ = $custom ? [] : ['cycle' => (int) $rc['id']];
    $filtered = $fv['sim_id'] || $fv['method'] || $fv['kind'] || $fv['q'];
@endphp
@include('datasharing._style')
<section class="ds">
    @unless ($exporting)
    <div class="ds-ph"><div><h1>Reports</h1><p>{{ $fv['subtitle'] }}</p></div>
        <div class="ds-tools">
            @include('datasharing._export', ['route' => 'datasharing.reports', 'qs' => $qs])
            @unless ($custom)<a class="ds-btn" href="{{ route('datasharing.deck', $deckQ) }}">Monthly presentation</a>@endunless
            @if ($canManage)
                <form method="POST" action="{{ route('datasharing.close') }}" style="display:inline" data-confirm="Close the books for cycle {{ $cyc['no'] }}? The cycle is saved with its presentation and locked. Unpaid clients move to cycle {{ $cyc['no'] + 1 }} with their due dates, and new packages for every SIM are added to Money Out.">@csrf
                    <button type="submit" class="ds-btn ds-btn--pri" @disabled(! $canClose) @if (! $canClose) title="Opens on {{ $d($ds->addDays($cyc['end'], 1)) }}" @endif>Close cycle {{ (int) $cyc['no'] }}</button>
                </form>
            @endif
        </div></div>
    @include('datasharing._top')
    @if ($canManage && ! $canClose)<p class="ds-hint">Close the books opens on {{ $d($ds->addDays($cyc['end'], 1)) }}, the 1st of the next month.@if ($firstCycle) Started the first cycle on the wrong day?
        <form method="POST" action="{{ route('datasharing.cycle-start') }}" style="display:inline-flex;gap:6px;align-items:center">@csrf <input type="hidden" name="return" value="{{ $here }}"><input class="ds-field" style="width:auto" type="date" name="start" value="{{ $cyc['start'] }}" max="{{ date('Y-m-d') }}" aria-label="First day of cycle 1"><button type="submit" class="ds-btn ds-btn--sm">Change start</button></form>@endif</p>@endif
    @include('datasharing._filters', ['fltAction' => 'datasharing.reports', 'fltFields' => ['period', 'sim', 'method', 'kind', 'q'], 'fltKeep' => [], 'fltQHint' => 'Client, data number or WhatsApp'])
    @if ($filtered)<p class="ds-hint">Filtered: money out shows only what matches the SIM and method (shared costs like transport have no SIM).</p>@endif
    @endunless

    <div class="ds-card"><div>
        <div class="ds-cols ds-cols--2">
            <div class="ds-tw">
                <div class="ds-card__h"><h2>Profit &amp; Loss</h2></div>
                <table class="ds-table">
                    <tbody>
                        <tr class="ds-group"><td>Income</td><td></td></tr>
                        <tr><td>&nbsp;&nbsp;Plan payments</td><td class="r">{{ $m($rd['plan_in']) }}</td></tr>
                        <tr><td>&nbsp;&nbsp;Recharges (second payments)</td><td class="r">{{ $m($rd['rech_in']) }}</td></tr>
                        @foreach (\App\Services\DataSharing\DataSharingService::INCOME_IN as $c)@if (! empty($rd['in_cat'][$c]))<tr><td>&nbsp;&nbsp;{{ $c }}</td><td class="r">{{ $m($rd['in_cat'][$c]) }}</td></tr>@endif @endforeach
                        <tr><td><b>Total income</b></td><td class="r"><b>{{ $m($rd['income'] + $rd['other_in']) }}</b></td></tr>
                        <tr class="ds-group"><td>Expenses</td><td></td></tr>
                        @foreach ($cats as $c)@if (! empty($rd['by_cat'][$c]))<tr><td>&nbsp;&nbsp;{{ $c }}</td><td class="r">{{ $m($rd['by_cat'][$c]) }}</td></tr>@endif @endforeach
                        <tr><td><b>Total expenses</b></td><td class="r"><b>{{ $m($rd['out']) }}</b></td></tr>
                    </tbody>
                    <tfoot><tr><td>Net profit</td><td class="r {{ $rd['net'] < 0 ? 'bad' : 'good' }}">{{ $m($rd['net']) }}</td></tr></tfoot>
                </table>
            </div>
            <div class="ds-tw">
                <div class="ds-card__h"><h2>By payment method</h2></div>
                <table class="ds-table">
                    <thead><tr><th>Method</th><th class="r">In</th><th class="r">Out</th><th class="r">Net</th></tr></thead>
                    <tbody>@foreach ($rd['methods'] as $x)<tr><td>{{ $x['m'] }}</td><td class="r">{{ $m($x['in']) }}</td><td class="r">{{ $m($x['out']) }}</td><td class="r"><b>{{ $m($x['in'] - $x['out']) }}</b></td></tr>@endforeach</tbody>
                    <tfoot><tr><td>Total</td><td class="r">{{ $m($rd['income'] + $rd['other_in'] + $rd['capital_in']) }}</td><td class="r">{{ $m($rd['out']) }}</td><td class="r">{{ $m($rd['income'] + $rd['other_in'] + $rd['capital_in'] - $rd['out']) }}</td></tr></tfoot>
                </table>
            </div>
        </div>

        <div class="ds-card__h" style="border-top:1px solid var(--line)"><h2>Payments by client</h2><span class="ds-hint">Plan payment and recharges together</span></div>
        <div class="ds-tw"><table class="ds-table">
            <thead><tr><th>Client</th><th>SIM</th><th>Plan</th><th class="r">Plan paid</th><th>Method</th><th class="r">Recharges</th><th class="r">Recharge amount</th><th>Recharge method</th><th class="r">Total</th></tr></thead>
            <tbody>
            @foreach ($rd['by_client'] as $x)
                <tr><td><b>{{ $x['name'] }}</b></td><td>{{ $x['sim'] }}</td><td>{{ (int) $x['gb'] }} GB</td><td class="r">{{ $x['plan_amt'] ? $m($x['plan_amt']) : '–' }}</td><td>{{ $x['plan_method'] ?: '–' }}</td>
                    <td class="r">{{ $x['rech_n'] ?: '–' }}</td><td class="r">{{ $x['rech_amt'] ? $m($x['rech_amt']) : '–' }}</td><td>{{ $x['rech_method'] ?: '–' }}</td><td class="r"><b>{{ $m($x['income']) }}</b></td></tr>
            @endforeach
            @if (! $rd['by_client'])<tr><td colspan="9" class="ds-empty">No payments found.</td></tr>@endif
            </tbody>
            <tfoot><tr><td colspan="3">Total</td><td class="r">{{ $m($rd['plan_in']) }}</td><td></td><td class="r">{{ array_sum(array_column($rd['by_client'], 'rech_n')) }}</td><td class="r">{{ $m($rd['rech_in']) }}</td><td></td><td class="r">{{ $m($rd['income']) }}</td></tr></tfoot>
        </table></div>

        <div class="ds-card__h" style="border-top:1px solid var(--line)"><h2>Every payment</h2><span class="ds-hint">{{ count($rd['pay_rows']) }} payments</span></div>
        <div class="ds-tw"><table class="ds-table">
            <thead><tr><th>Date</th><th>Client</th><th>SIM</th><th>Type</th><th class="r">GB</th><th>Method</th><th>Data came from</th><th class="r">Amount</th>@if ($canManage && ! $exporting)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($rd['pay_rows'] as $p)
                <tr><td>{{ $d($p['date']) }}</td><td>{{ $p['client'] }}</td><td>{{ $p['sim'] }}</td><td>{{ $p['type'] }}</td><td class="r">{{ (int) $p['gb'] }}</td><td>{{ $p['method'] }}</td><td>{{ $p['src'] }}</td><td class="r">{{ $m($p['amount']) }}</td>
                    @if ($canManage && ! $exporting)<td class="r">@if ($p['cycle_id'] === (int) $cyc['id'])<form method="POST" action="{{ route('datasharing.payment-void', $p['id']) }}" data-confirm="Void this payment? Its entry is removed from the books.">@csrf <input type="hidden" name="return" value="{{ $here }}"><button type="submit" class="ds-btn ds-btn--sm ds-btn--bad">Void</button></form>@endif</td>@endif</tr>
            @endforeach
            @if (! $rd['pay_rows'])<tr><td colspan="9" class="ds-empty">No payments found.</td></tr>@endif
            </tbody>
            <tfoot><tr><td colspan="7">Total</td><td class="r">{{ $m($rd['income']) }}</td>@if ($canManage && ! $exporting)<td></td>@endif</tr></tfoot>
        </table></div>

        @if ($rd['in_rows'])
        <div class="ds-card__h" style="border-top:1px solid var(--line)"><h2>Other money in</h2><span class="ds-hint">Owner money and loans are not income</span></div>
        <div class="ds-tw"><table class="ds-table">
            <thead><tr><th>Date</th><th>For</th><th>Details</th><th>Received by</th><th class="r">Amount</th></tr></thead>
            <tbody>@foreach ($rd['in_rows'] as $x)<tr><td>{{ $d($x['date']) }}</td><td>{{ $x['cat'] }}</td><td>{{ $x['text'] }}</td><td>{{ $x['method'] }}</td><td class="r">{{ $m($x['amount']) }}</td></tr>@endforeach</tbody>
            <tfoot><tr><td colspan="4">Total</td><td class="r">{{ $m($rd['other_in'] + $rd['capital_in']) }}</td></tr></tfoot>
        </table></div>
        @endif
        @if ($rd['exp_rows'])
        <div class="ds-card__h" style="border-top:1px solid var(--line)"><h2>Money out</h2><span class="ds-hint">{{ count($rd['exp_rows']) }} payments</span></div>
        <div class="ds-tw"><table class="ds-table">
            <thead><tr><th>Date</th><th>For</th><th>Details</th><th>SIM</th><th>Paid from</th><th class="r">Amount</th></tr></thead>
            <tbody>@foreach ($rd['exp_rows'] as $x)<tr><td>{{ $d($x['date']) }}</td><td>{{ $x['cat'] }}</td><td>{{ $x['text'] }}</td><td>{{ $x['sim'] }}</td><td>{{ $x['method'] }}</td><td class="r">{{ $m($x['amount']) }}</td></tr>@endforeach</tbody>
            <tfoot><tr><td colspan="5">Total</td><td class="r">{{ $m($rd['out']) }}</td></tr></tfoot>
        </table></div>
        @endif
    </div></div>

    @if (! $exporting && $closed)
    <div class="ds-card"><div class="ds-card__h"><h2>Closed cycles</h2></div>
        @foreach ($closed as $c) @php $snap = json_decode((string) $c['snapshot'], true); @endphp
            <div class="ds-li"><div><b>Cycle {{ (int) $c['no'] }}</b><small>{{ $d($c['start']) }} &ndash; {{ $d($c['end']) }} &middot; closed {{ $d($c['closed_on']) }}{{ $c['closed_by'] ? ' by '.$c['closed_by'] : '' }}</small></div>
                <div class="amt">{{ $m(is_array($snap) ? $snap['net'] : 0) }}<small>net profit</small></div>
                <div class="acts"><a class="ds-btn ds-btn--sm" href="{{ route('datasharing.deck', ['cycle' => (int) $c['id']]) }}">Presentation</a><a class="ds-btn ds-btn--sm" href="{{ route('datasharing.reports', ['cycle' => (int) $c['id']]) }}">Open reports</a></div></div>
        @endforeach
    </div>
    @endif
</section>
@unless ($exporting)@include('datasharing._modals')@endunless
@endsection
