@extends('layouts.main')
@section('title', 'Chart of Accounts')
@section('content')
@php
    use App\Services\Accounting\ChartOfAccounts as Chart;
    use App\Support\Money;
    // a group is "non-zero" when it, or an account somewhere inside it, holds a balance
    $nonZero = [];
    foreach ($rows as $r) {
        if (! Money::isZero($r['balance'])) {
            if ($r['kind'] === 'group') { $nonZero[$r['id']] = true; }
            foreach ($r['path'] as $gid) { $nonZero[$gid] = true; }
        }
    }
    // negatives in brackets: a balance on the "wrong" side for its type stands out
    $money = fn ($v) => Money::cmp($v, 0) < 0 ? '('.ltrim(Money::format(Money::neg($v)), '-').')' : Money::format($v);
@endphp

@if (! Money::isZero($opDiff['amount']))
    <div class="flash bad"><strong>The opening balances don't balance.</strong> The difference is {{ Money::format($opDiff['amount']) }} on the {{ $opDiff['dc'] === 'D' ? 'debit' : 'credit' }} side.</div>
@endif

<div class="bar">
    <div><h1>Chart of Accounts</h1><div class="muted">Every account in your books, grouped. Balances are as of today.</div></div>
    <div class="actions">
        <a class="btn ghost" href="{{ route('groups.create') }}">New group</a>
        <a class="btn" href="{{ route('ledgers.create') }}">{{ \App\Support\Icon::svg('plus', 16) }}New account</a>
    </div>
</div>

<div class="stats" id="types">
    @foreach (Chart::TYPES as $type)
        <button type="button" data-type="{{ $type }}"><b>{{ $money($totals[$type]['total']) }}</b><span>{{ Chart::plural($type) }} &middot; {{ $totals[$type]['count'] }} account{{ $totals[$type]['count'] === 1 ? '' : 's' }}</span></button>
    @endforeach
</div>

<div class="actions" style="margin-bottom:12px;align-items:center">
    <input id="q" type="search" placeholder="Find an account" autocomplete="off" style="max-width:260px">
    <button type="button" class="ghost sm" id="openAll">Open all</button>
    <button type="button" class="ghost sm" id="closeAll">Close all</button>
    <label style="margin:0;font-weight:400"><input type="checkbox" id="hideZero"> Hide zero balances</label>
    <span id="note" class="muted"></span>
</div>

<div class="card tbl">
    <table id="chart">
        <thead><tr><th>Account</th><th>Type</th><th class="num">Balance</th><th></th></tr></thead>
        <tbody>
        @foreach ($rows as $r)
            @php
                $isGroup = $r['kind'] === 'group';
                $zero = $isGroup ? empty($nonZero[$r['id']]) : Money::isZero($r['balance']);
            @endphp
            <tr data-id="{{ $r['id'] }}" data-kind="{{ $r['kind'] }}" data-path="{{ implode(',', $r['path']) }}" data-type="{{ $r['type'] }}" data-zero="{{ $zero ? 1 : 0 }}" data-search="{{ strtolower($r['name']) }}" @class(['group' => $isGroup, 'root' => $isGroup && $r['root']])>
                <td style="padding-left:{{ 8 + $r['depth'] * 22 }}px">
                    @if ($isGroup)
                        <button type="button" class="link tgl" data-group="{{ $r['id'] }}" aria-expanded="true" aria-label="Open or close {{ $r['name'] }}">&#9662;</button>
                        <strong>{{ $r['name'] }}</strong>
                        @if ($r['customers'] || $r['vendors'])
                            <span class="muted">includes
                                @if ($r['customers'])<a href="{{ route('customers.index') }}">{{ $r['customers'] }} customer{{ $r['customers'] == 1 ? '' : 's' }}</a>@endif
                                @if ($r['customers'] && $r['vendors']) and @endif
                                @if ($r['vendors'])<a href="{{ route('vendors.index') }}">{{ $r['vendors'] }} vendor{{ $r['vendors'] == 1 ? '' : 's' }}</a>@endif
                            </span>
                        @endif
                    @else
                        <span style="display:inline-block;width:1.2em"></span>{{ $r['name'] }}
                        @if ($r['bank'])<span class="pill owes" title="Money can be received into and paid from this account">Bank/Cash</span>@endif
                        @if ($r['contra'])<span class="pill" title="Reduces the other accounts in its group, so its balance runs the other way">Contra</span>@endif
                    @endif
                </td>
                <td><span class="pill">{{ $r['type'] }}</span></td>
                <td class="num" @if (Money::cmp($r['balance'], 0) < 0) style="color:var(--danger)" title="This balance runs the other way from normal for {{ strtolower(Chart::plural($r['type'])) }}" @endif>{{ $money($r['balance']) }}</td>
                <td>
                    <div class="actions" style="justify-content:flex-end">
                        @if ($isGroup)
                            <a class="btn ghost sm" href="{{ route('ledgers.create', ['group' => $r['id']]) }}">+ Account</a>
                            <a class="btn ghost sm" href="{{ route('groups.create', ['parent' => $r['id']]) }}">+ Group</a>
                            @unless ($r['root'])
                                <a class="btn ghost sm" href="{{ route('groups.edit', $r['id']) }}">Edit</a>
                                @if (! $r['child_groups'] && ! $r['child_accounts'])
                                    <form class="inline" method="POST" action="{{ route('groups.destroy', $r['id']) }}" onsubmit="return confirm('Delete the group &quot;{{ addslashes($r['name']) }}&quot;?')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form>
                                @endif
                            @endunless
                        @else
                            <a class="btn ghost sm" href="{{ route('ledgers.edit', $r['id']) }}">Edit</a>
                            @unless ($r['lines'])
                                <form class="inline" method="POST" action="{{ route('ledgers.destroy', $r['id']) }}" onsubmit="return confirm('Delete the account &quot;{{ addslashes($r['name']) }}&quot;?')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form>
                            @endunless
                        @endif
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p id="empty" class="muted" style="text-align:center;padding:16px" hidden>No account matches your search.</p>
</div>

<p class="muted" style="font-size:13px">
    Balances are as of today. A balance in (brackets) runs the other way from normal for its type.
    @if ($fyFrom)
        Income and expense accounts show this financial year only (from {{ Money::date($fyFrom) }})@unless (Money::isZero($priorProfit))
        ; the profit of earlier years, {{ Money::format(ltrim($priorProfit, '-')) }}{{ Money::cmp($priorProfit, 0) < 0 ? ' (a loss)' : '' }}, is in Retained Earnings on the Balance Sheet @endunless.
    @endif
</p>

<style>
    #chart tr.root td { background:var(--brand-soft); color:var(--brand-dark); }
    #chart tr.group td { font-weight:600; }
    #chart tr.flash-row td { background:var(--brand-soft); transition:background 2s; }
    .tgl { font-size:15px; text-decoration:none; width:1.4em; color:var(--muted); }
    .tgl.closed { transform:rotate(-90deg); display:inline-block; }
    #types button.on { border-color:var(--brand); box-shadow:0 0 0 1px var(--brand); }
</style>
<script>
(function () {
    var KEY = 'coaClosed.v1', table = document.getElementById('chart'), rows = [].slice.call(table.querySelectorAll('tbody tr'));
    var state = { closed: load(), type: '', q: '', hideZero: false };

    function load() {
        try { var raw = localStorage.getItem(KEY); if (raw) return JSON.parse(raw); } catch (e) {}
        // first visit: the top groups and the ones right under them show; everything deeper starts closed
        return rows.filter(function (r) { return r.dataset.kind === 'group' && pathOf(r).length >= 1; }).map(function (r) { return r.dataset.id; });
    }
    function save() { try { localStorage.setItem(KEY, JSON.stringify(state.closed)); } catch (e) {} }
    function pathOf(r) { return r.dataset.path ? r.dataset.path.split(',') : []; }
    // every word typed must start a word of the name ("rent" finds Rent, not cuRRENT assets)
    function matches(text, q) {
        if (!q) return true;
        var words = text.split(/[^a-z0-9&]+/).filter(Boolean);
        return q.split(/\s+/).every(function (w) { return words.some(function (x) { return x.indexOf(w) === 0; }); });
    }

    function render() {
        var filtering = state.q !== '' || state.type !== '', show = {}, matched = {};
        if (filtering) {
            rows.forEach(function (r) {
                if ((!state.type || r.dataset.type === state.type) && matches(r.dataset.search, state.q)) {
                    show[r.dataset.kind + r.dataset.id] = true;
                    if (r.dataset.kind === 'group' && state.q) matched[r.dataset.id] = true;
                    pathOf(r).forEach(function (g) { show['group' + g] = true; });
                }
            });
            if (state.q) rows.forEach(function (r) {
                if ((!state.type || r.dataset.type === state.type) && pathOf(r).some(function (g) { return matched[g]; })) show[r.dataset.kind + r.dataset.id] = true;
            });
        }
        var shown = 0;
        rows.forEach(function (r) {
            var visible = filtering ? !!show[r.dataset.kind + r.dataset.id] : !pathOf(r).some(function (g) { return state.closed.indexOf(g) !== -1; });
            if (visible && state.hideZero && r.dataset.zero === '1') visible = false;
            r.hidden = !visible; if (visible) shown++;
        });
        [].forEach.call(table.querySelectorAll('.tgl'), function (b) {
            var open = filtering || state.closed.indexOf(b.dataset.group) === -1;
            b.setAttribute('aria-expanded', open ? 'true' : 'false'); b.classList.toggle('closed', !open);
        });
        document.getElementById('empty').hidden = shown > 0;
        [].forEach.call(document.querySelectorAll('#types button'), function (b) { b.classList.toggle('on', b.dataset.type === state.type); });
        document.getElementById('note').textContent = state.type ? 'Showing ' + state.type + ' only — click it again to show all.' : '';
    }

    table.addEventListener('click', function (e) {
        var b = e.target.closest('.tgl'); if (!b || state.q || state.type) return;
        var i = state.closed.indexOf(b.dataset.group);
        if (i === -1) state.closed.push(b.dataset.group); else state.closed.splice(i, 1);
        save(); render();
    });
    document.getElementById('openAll').onclick = function () { state.closed = []; save(); render(); };
    document.getElementById('closeAll').onclick = function () {
        state.closed = rows.filter(function (r) { return r.dataset.kind === 'group'; }).map(function (r) { return r.dataset.id; }); save(); render();
    };
    document.getElementById('hideZero').onchange = function () { state.hideZero = this.checked; render(); };
    var t = null, q = document.getElementById('q');
    q.addEventListener('input', function () { clearTimeout(t); var v = q.value.trim().toLowerCase(); t = setTimeout(function () { state.q = v; render(); }, 120); });
    [].forEach.call(document.querySelectorAll('#types button'), function (b) {
        b.onclick = function () { state.type = state.type === b.dataset.type ? '' : b.dataset.type; render(); };
    });

    // after saving: open the groups above it and point at it (?show=12 for an account, ?show=g5 for a group)
    var m = /[?&]show=(g?)(\d+)/.exec(location.search);
    if (m) {
        var target = rows.filter(function (r) { return r.dataset.kind === (m[1] ? 'group' : 'account') && r.dataset.id === m[2]; })[0];
        if (target) { pathOf(target).forEach(function (g) { var i = state.closed.indexOf(g); if (i !== -1) state.closed.splice(i, 1); }); save(); }
        render();
        if (target) {
            target.classList.add('flash-row'); setTimeout(function () { target.scrollIntoView({ block: 'center' }); }, 50);
            setTimeout(function () { target.classList.remove('flash-row'); }, 2600);
        }
        if (history.replaceState) history.replaceState(null, '', location.pathname);
    } else render();
})();
</script>
@endsection
