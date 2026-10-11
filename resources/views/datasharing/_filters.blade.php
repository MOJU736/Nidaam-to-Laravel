{{-- Filter bar for Data Sharing pages and reports. Set before including: $fltAction (route name), $fltFields (period, cycle, sim, type, plan, method, kind, cat, due, q), $fltKeep (extra GET values to keep), $fltQHint, $fltKindOther. Reads $fv (the controller's read filters). --}}
@unless ($exporting)
@php
    $fvx = $fv ?? [];
    $val = fn ($k) => isset($fvx[$k]) && $fvx[$k] !== null ? $fvx[$k] : '';
    $isCustom = ! empty($fvx) && empty($fvx['cycle_id']);
    $any = false;
    foreach (['sim', 'type', 'plan', 'method', 'kind', 'cat', 'due', 'q', 'from', 'to', 'cycle'] as $k) { if ((string) request()->query($k, '') !== '') { $any = true; } }
    $fltKeep = $fltKeep ?? [];
@endphp
<form method="get" action="{{ route($fltAction) }}" class="ds-filters" role="search" aria-label="Filters">
    @foreach ($fltKeep as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    @if (in_array('period', $fltFields, true))
        <label><span>Period</span><select class="ds-field" name="cycle" data-ds-period>
            @foreach ($fCycles as $cy)<option value="{{ (int) $cy['id'] }}" @selected(! $isCustom && (int) $val('cycle_id') === (int) $cy['id'])>Cycle {{ (int) $cy['no'] }}{{ $cy['status'] === 'open' ? ' (open)' : '' }}</option>@endforeach
            <option value="custom" @selected($isCustom)>Choose dates&hellip;</option>
        </select></label>
        <label data-ds-dates {{ $isCustom ? '' : 'hidden' }}><span>From</span><input class="ds-field" type="date" name="from" value="{{ $val('from') }}"></label>
        <label data-ds-dates {{ $isCustom ? '' : 'hidden' }}><span>To</span><input class="ds-field" type="date" name="to" value="{{ $val('to') }}"></label>
    @endif
    @if (in_array('cycle', $fltFields, true))
        <label><span>Cycle</span><select class="ds-field" name="cycle">
            @foreach ($fCycles as $cy)<option value="{{ (int) $cy['id'] }}" @selected((int) $val('cycle_id') === (int) $cy['id'])>Cycle {{ (int) $cy['no'] }}{{ $cy['status'] === 'open' ? ' (open)' : '' }}</option>@endforeach
        </select></label>
    @endif
    @if (in_array('sim', $fltFields, true))
        <label><span>SIM</span><select class="ds-field" name="sim"><option value="">All SIMs</option>@foreach ($fSims as $sm)<option value="{{ (int) $sm['id'] }}" @selected((int) $val('sim_id') === (int) $sm['id'])>{{ $sm['number'] }}{{ $sm['status'] !== 'active' ? ' (removed)' : '' }}</option>@endforeach</select></label>
    @endif
    @if (in_array('type', $fltFields, true))
        <label><span>SIM type</span><select class="ds-field" name="type"><option value="">Shared and full</option><option value="shared" @selected($val('type') === 'shared')>Shared (5 slots)</option><option value="full" @selected($val('type') === 'full')>Full SIM</option></select></label>
    @endif
    @if (in_array('plan', $fltFields, true))
        <label><span>Plan</span><select class="ds-field" name="plan"><option value="">All plans</option>@foreach ($plansAll as $pl)<option value="{{ (int) $pl['gb'] }}" @selected((int) $val('plan') === (int) $pl['gb'])>{{ (int) $pl['gb'] }} GB</option>@endforeach</select></label>
    @endif
    @if (in_array('method', $fltFields, true))
        <label><span>Method</span><select class="ds-field" name="method"><option value="">All methods</option>@foreach (\App\Services\DataSharing\DataSharingService::METHODS as $mm)<option @selected($val('method') === $mm)>{{ $mm }}</option>@endforeach</select></label>
    @endif
    @if (in_array('kind', $fltFields, true))
        <label><span>Type</span><select class="ds-field" name="kind"><option value="">{{ ! empty($fltKindOther) ? 'Everything' : 'Plan payments and recharges' }}</option><option value="plan" @selected($val('kind') === 'plan')>Plan payments only</option><option value="recharge" @selected($val('kind') === 'recharge')>Recharges only</option>@if (! empty($fltKindOther))<option value="other" @selected($val('kind') === 'other')>Other money in only</option>@endif</select></label>
    @endif
    @if (in_array('cat', $fltFields, true))
        <label><span>For</span><select class="ds-field" name="cat"><option value="">Everything</option>@foreach (array_keys(\App\Services\DataSharing\DataSharingService::CATS) as $ct)<option @selected($val('cat') === $ct)>{{ $ct }}</option>@endforeach</select></label>
    @endif
    @if (in_array('due', $fltFields, true))
        @php $dv = (int) request()->query('due'); @endphp
        <label><span>Due</span><select class="ds-field" name="due"><option value="">Any time</option>@foreach ([3 => 'Within 3 days', 7 => 'Within 7 days', 14 => 'Within 14 days', 30 => 'Within 30 days'] as $k => $t)<option value="{{ $k }}" @selected($dv === $k)>{{ $t }} (and overdue)</option>@endforeach</select></label>
    @endif
    @if (in_array('q', $fltFields, true))
        <label class="ds-filters__q"><span>Search</span><input class="ds-field" type="search" name="q" value="{{ $val('q') !== '' ? $val('q') : request()->query('q') }}" placeholder="{{ $fltQHint ?? 'Name, phone or SIM number' }}"></label>
    @endif
    <div><button type="submit" class="ds-btn ds-btn--sm ds-btn--pri">Apply</button>@if ($any) <a class="ds-btn ds-btn--sm" href="{{ route($fltAction).(! empty($fltKeep) ? '?'.http_build_query($fltKeep) : '') }}">Clear</a>@endif</div>
</form>
<script>
(function () {
    var f = document.currentScript.previousElementSibling; if (!f) { return; }
    var per = f.querySelector('[data-ds-period]');
    if (per) { per.addEventListener('change', function () { var c = per.value === 'custom'; f.querySelectorAll('[data-ds-dates]').forEach(function (l) { l.hidden = !c; }); if (!c) { f.querySelectorAll('[data-ds-dates] input').forEach(function (i) { i.value = ''; }); f.submit(); } }); }
    f.querySelectorAll('select:not([data-ds-period])').forEach(function (s) { s.addEventListener('change', function () { f.submit(); }); });
    f.addEventListener('submit', function () {
        if (per && per.value === 'custom') { per.disabled = true; }
        if (per && per.value !== 'custom') { f.querySelectorAll('[data-ds-dates] input').forEach(function (i) { i.disabled = true; }); }
        f.querySelectorAll('select, input').forEach(function (el) { if (el.value === '' && el.type !== 'hidden') { el.disabled = true; } });
    });
})();
</script>
@endunless
