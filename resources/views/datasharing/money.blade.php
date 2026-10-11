@extends($exporting ? 'datasharing.bare' : 'layouts.main')
@section('title', 'Data Sharing - Money Out')
@section('content')
@php
    use App\Support\DsView as V;
    $m = fn ($v) => V::m($v); $d = fn ($v) => V::d($v);
    $rows = $rd['exp_rows']; $bc = $rd['by_cat'];
    $qs = array_filter(['cycle' => request()->query('cycle'), 'from' => request()->query('from'), 'to' => request()->query('to'), 'cat' => request()->query('cat'), 'method' => request()->query('method'), 'sim' => request()->query('sim'), 'q' => request()->query('q')]);
    $pk = $bc['Data packages'] ?? 0; $ex = $bc['Extra data'] ?? 0;
@endphp
@include('datasharing._style')
<section class="ds">
    @unless ($exporting)
    <div class="ds-ph"><div><h1>Money Out</h1><p>{{ $fv['subtitle'] }}. SIM packages are added by themselves when a cycle starts; extra data comes from recharges.</p></div>
        <div class="ds-tools">@include('datasharing._export', ['route' => 'datasharing.money', 'qs' => $qs])<button type="button" class="ds-btn ds-btn--pri" data-open="dsExp">+ Record money out</button></div></div>
    @include('datasharing._top')
    @include('datasharing._filters', ['fltAction' => 'datasharing.money', 'fltFields' => ['period', 'cat', 'method', 'sim', 'q'], 'fltKeep' => [], 'fltQHint' => 'Details, e.g. taxi'])
    <div class="ds-kpis">
        <div class="ds-kpi bad"><small>Spent</small><strong>{{ $m($rd['out']) }}</strong><span>{{ count($rows) }} payments</span></div>
        <div class="ds-kpi"><small>Data packages</small><strong>{{ $m($pk) }}</strong><span>added by themselves</span></div>
        <div class="ds-kpi"><small>Extra data</small><strong>{{ $m($ex) }}</strong><span>bought for recharges</span></div>
        <div class="ds-kpi"><small>Other</small><strong>{{ $m($rd['out'] - $pk - $ex) }}</strong><span>recorded by you</span></div>
    </div>
    @endunless
    <div class="ds-card"><div class="ds-tw">
        <table class="ds-table">
            <thead><tr><th>Date</th><th>For</th><th>Details</th><th>SIM</th><th>Paid from</th><th class="r">Amount</th>@if ($canManage && ! $exporting)<th></th>@endif</tr></thead>
            <tbody>
            @foreach ($rows as $x)
                <tr><td>{{ $d($x['date']) }}</td><td>@if ($exporting){{ $x['cat'] }}@else<span class="ds-pill {{ $x['auto'] ? '' : 'warn' }}">{{ $x['cat'] }}</span>@endif</td><td>{{ $x['text'] }}@if ($x['auto'] && ! $exporting) <small class="ds-hint">&middot; automatic</small>@endif</td><td>{{ $x['sim'] }}</td><td>{{ $x['method'] }}</td><td class="r">{{ $m($x['amount']) }}</td>
                    @if ($canManage && ! $exporting)<td class="r">@if ((int) $x['cycle_id'] === (int) $cyc['id'])<form method="POST" action="{{ route('datasharing.expense-void', $x['id']) }}" data-confirm="Void this payment? Its entry is removed from the books.">@csrf <input type="hidden" name="return" value="{{ $here }}"><button type="submit" class="ds-btn ds-btn--sm ds-btn--bad">Void</button></form>@endif</td>@endif</tr>
            @endforeach
            @if (! $rows)<tr><td colspan="7" class="ds-empty">Nothing found.</td></tr>@endif
            </tbody>
            <tfoot><tr><td colspan="5">Total</td><td class="r">{{ $m($rd['out']) }}</td>@if ($canManage && ! $exporting)<td></td>@endif</tr></tfoot>
        </table>
    </div></div>
</section>
@unless ($exporting)
<dialog class="ds-modal" id="dsExp">
    <form method="POST" action="{{ route('datasharing.expense-add') }}">@csrf <input type="hidden" name="return" value="{{ $here }}">
        <h3>Record money out</h3>
        <div class="ds-form">
            <div class="ds-row2"><label>For<select class="ds-field" name="cat">@foreach (array_keys(\App\Services\DataSharing\DataSharingService::CATS) as $k)<option @selected($k === 'Transport')>{{ $k }}</option>@endforeach</select></label><label>Amount<input class="ds-field" name="amount" placeholder="0.00" inputmode="decimal" required></label></div>
            <label>Details <small>(optional)</small><input class="ds-field" name="text" placeholder="e.g. Taxi to collect payments"></label>
            <div class="ds-row2"><label>Paid from<select class="ds-field" name="method">{{ V::methodOptions('Cash') }}</select></label><label>Date<input class="ds-field" type="date" name="date" value="{{ date('Y-m-d') }}" min="{{ $cyc['start'] }}" max="{{ date('Y-m-d') }}" required></label></div>
        </div>
        <div class="ds-foot"><button type="button" class="ds-btn" data-close>Cancel</button><button type="submit" class="ds-btn ds-btn--pri">Save</button></div>
    </form>
</dialog>
@include('datasharing._modals')
@endunless
@endsection
