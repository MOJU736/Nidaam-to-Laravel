@extends('layouts.app')
@section('body')
@php
    use App\Support\Icon;
    $me = auth()->user();
    $perm = app(\App\Services\Auth\Permissions::class);
    $group = $perm->groupName($me);
    $company = app(\App\Tenancy\TenantManager::class)->current()?->name;
    $dsOnly = \App\Services\DataSharing\DataSharingService::only() && ! $me->isAdmin() && ! in_array($group, [\App\Services\Auth\Permissions::RESTRICTED, \App\Services\Auth\Permissions::CUSTOMER_VIEWER], true);
    $initials = mb_strtoupper(mb_substr($me->first_name ?: $me->username, 0, 1).mb_substr($me->last_name ?: '', 0, 1));
    // [heading, [[route to check, label, icon, active route patterns, show?]]]
    if ($dsOnly) {
        $menu = [[null, array_map(fn ($r) => [$r[0], $r[1], $r[2], [$r[0]], true], [['datasharing.index', 'Today', 'dashboard'], ['datasharing.sims', 'SIM board', 'datasharing'], ['datasharing.clients', 'Clients', 'customers'], ['datasharing.money-in', 'Money In', 'sales'], ['datasharing.money', 'Money Out', 'purchases'], ['datasharing.profit', 'Profit', 'reports'], ['datasharing.reports', 'Reports', 'entries']])]];
    } else {
        $menu = [
            [null, [['dashboard', 'Dashboard', 'dashboard', ['dashboard'], true]]],
            ['Money in & out', [
                ['invoices.index', 'Sales', 'sales', ['invoices.*', 'quotations.*', 'payments.*', 'sales.*', 'credit-notes.*', 'recurring.*', 'reminders.*'], true],
                ['customers.index', 'Customers', 'customers', ['customers.*'], true],
                ['bills.index', 'Purchases', 'purchases', ['bills.*', 'purchase-orders.*', 'vendor-payments.*', 'expenses.*', 'purchases.*', 'supplier-credits.*'], true],
                ['vendors.index', 'Vendors', 'vendors', ['vendors.*'], true],
                ['commissions.index', 'Commissions', 'commissions', ['commissions.*'], \App\Services\Commissions\CommissionService::enabled()],
                ['banking.index', 'Banking', 'banking', ['banking.*'], true],
            ]],
            ['Stock & work', [
                ['items.index', 'Items', 'items', ['items.*'], true],
                ['projects.index', 'Projects', 'projects', ['projects.*', 'construction.*'], \App\Support\Money::constructionEnabled()],
                ['payroll.index', 'Payroll', 'payroll', ['payroll.*'], true],
                ['datasharing.index', 'Data Sharing', 'datasharing', ['datasharing.*'], \App\Services\DataSharing\DataSharingService::enabled()],
            ]],
            ['The books', [
                ['entries.index', 'Entries', 'entries', ['entries.*'], true],
                ['chart', 'Accounts', 'accounts', ['chart', 'ledgers.*', 'groups.*'], true],
                ['reports.index', 'Reports', 'reports', ['reports.*'], true],
            ]],
            ['Manage', [
                ['settings.index', 'Settings', 'settings', ['settings.*'], true],
                ['admin.users', 'Admin', 'admin', ['admin.*'], $me->isAdmin()],
            ]],
        ];
    }
@endphp
<div class="shell">
<aside class="side" id="side" aria-label="Main menu">
    <a class="brand" href="{{ route($dsOnly ? 'datasharing.index' : 'dashboard') }}">{{ Icon::mark(30) }}<b>Nidaam</b></a>
    <div class="co"><span><small>Company</small><strong title="{{ $company }}">{{ $company }}</strong></span><a href="{{ route('companies.index') }}" title="Switch company" aria-label="Switch company">{{ Icon::svg('switch', 18) }}</a></div>
    <nav>
        @foreach ($menu as [$heading, $items])
            @php $items = array_values(array_filter($items, fn ($i) => $i[4] && $perm->allows($me, $i[0]))); @endphp
            @if ($items)
                @if ($heading)<div class="grp">{{ $heading }}</div>@endif
                @foreach ($items as [$route, $label, $icon, $match])<a href="{{ route($route) }}" @class(['on' => request()->routeIs(...$match)]) @if (request()->routeIs(...$match)) aria-current="page" @endif>{{ Icon::svg($icon) }}<span>{{ $label }}</span></a>@endforeach
            @endif
        @endforeach
    </nav>
    <div class="me">
        <div class="av">{{ $initials }}</div>
        <div class="who"><b>{{ $me->displayName() }}</b><small>{{ ucfirst(str_replace('_', ' ', $group)) }}</small></div>
        <button type="button" class="icobtn" id="themeBtn" title="Light or dark" aria-label="Switch between light and dark">{{ Icon::svg('moon', 18) }}</button>
        <a class="icobtn" href="{{ route('password.change') }}" title="Change your password" aria-label="Change your password">{{ Icon::svg('key', 18) }}</a>
        <form method="POST" action="{{ route('logout') }}">@csrf <button class="icobtn" title="Sign out" aria-label="Sign out">{{ Icon::svg('logout', 18) }}</button></form>
    </div>
</aside>
<div class="scrim" id="scrim"></div>
<div class="main">
    <div class="topbar"><button type="button" class="icobtn" id="navBtn" aria-label="Open the menu" aria-controls="side">{{ Icon::svg('menu', 22) }}</button>{{ Icon::mark(24) }}<b>Nidaam</b><span class="sp"></span><span class="muted" style="font-size:13px;max-width:45vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $company }}</span></div>
    <div class="wide">
        @if (session('message'))<div class="flash ok">{{ session('message') }}</div>@endif
        @if (session('warning'))<div class="flash bad">{{ session('warning') }}</div>@endif
        @if (session('error'))<div class="flash bad">{{ session('error') }}</div>@endif
        @foreach (['party', 'ledger', 'group', 'entry', 'item', 'category', 'vehicle', 'prices', 'batch', 'lines', 'adjustment', 'sales', 'purchases', 'credit', 'banking', 'recurring', 'reminders', 'payroll', 'settings', 'projects', 'commissions'] as $k)@if ($errors->has($k))<div class="flash bad">{{ $errors->first($k) }}</div>@endif @endforeach
        @yield('content')
    </div>
</div>
</div>
<script>
(function () {
    var b = document.body, q = function (s) { return document.getElementById(s); };
    q('navBtn').addEventListener('click', function () { b.classList.toggle('nav-open'); });
    q('scrim').addEventListener('click', function () { b.classList.remove('nav-open'); });
    var root = document.documentElement, btn = q('themeBtn');
    var dark = function () { var t = root.getAttribute('data-theme'); return t ? t === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches; };
    btn.addEventListener('click', function () { var t = dark() ? 'light' : 'dark'; root.setAttribute('data-theme', t); try { localStorage.setItem('nd_theme', t); } catch (e) {} });
})();
(function () {
    // signed out after 30 minutes without activity: this page's own timer (the server checks too); real activity pings the server at most every 5 minutes
    var IDLE = 30 * 60 * 1000, last = Date.now(), sent = Date.now();
    var act = function () { last = Date.now(); if (last - sent > 5 * 60 * 1000) { sent = last; fetch({{ \Illuminate\Support\Js::from(route('session.keepalive')) }}, { method: 'POST', headers: { 'X-CSRF-TOKEN': {{ \Illuminate\Support\Js::from(csrf_token()) }}, 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).catch(function () {}); } };
    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (e) { addEventListener(e, act, { passive: true }); });
    setInterval(function () { if (Date.now() - last > IDLE) { location.href = {{ \Illuminate\Support\Js::from(route('session.timeout')) }}; } }, 30000);
})();
</script>
@endsection
