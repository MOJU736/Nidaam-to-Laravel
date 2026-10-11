@extends('layouts.app')
@section('body')
<nav class="top">
    <div class="in">
        @if (\App\Services\DataSharing\DataSharingService::only() && ! auth()->user()->isAdmin() && ! in_array(app(\App\Services\Auth\Permissions::class)->groupName(auth()->user()), [\App\Services\Auth\Permissions::RESTRICTED, \App\Services\Auth\Permissions::CUSTOMER_VIEWER], true))
            @foreach ([['datasharing.index', 'Today'], ['datasharing.sims', 'SIM board'], ['datasharing.clients', 'Clients'], ['datasharing.money-in', 'Money In'], ['datasharing.money', 'Money Out'], ['datasharing.profit', 'Profit'], ['datasharing.reports', 'Reports']] as [$r, $label])<a href="{{ route($r) }}" @class(['on' => request()->routeIs($r)])>{{ $label }}</a>@endforeach
        @else
        @can('page', 'dashboard')<a href="{{ route('dashboard') }}" @class(['on' => request()->routeIs('dashboard')])>Dashboard</a>@endcan
        @can('page', 'entries.index')<a href="{{ route('entries.index') }}" @class(['on' => request()->routeIs('entries.*')])>Entries</a>@endcan
        @can('page', 'chart')<a href="{{ route('chart') }}" @class(['on' => request()->routeIs('chart', 'ledgers.*', 'groups.*')])>Accounts</a>@endcan
        @can('page', 'invoices.index')<a href="{{ route('invoices.index') }}" @class(['on' => request()->routeIs('invoices.*', 'quotations.*', 'payments.*', 'sales.*', 'credit-notes.*', 'recurring.*', 'reminders.*')])>Sales</a>@endcan
        @can('page', 'bills.index')<a href="{{ route('bills.index') }}" @class(['on' => request()->routeIs('bills.*', 'purchase-orders.*', 'vendor-payments.*', 'expenses.*', 'purchases.*', 'supplier-credits.*')])>Purchases</a>@endcan
        @can('page', 'items.index')<a href="{{ route('items.index') }}" @class(['on' => request()->routeIs('items.*')])>Items</a>@endcan
        @can('page', 'customers.index')<a href="{{ route('customers.index') }}" @class(['on' => request()->routeIs('customers.*')])>Customers</a>@endcan
        @can('page', 'vendors.index')<a href="{{ route('vendors.index') }}" @class(['on' => request()->routeIs('vendors.*')])>Vendors</a>@endcan
        @if (\App\Support\Money::constructionEnabled())@can('page', 'projects.index')<a href="{{ route('projects.index') }}" @class(['on' => request()->routeIs('projects.*', 'construction.*')])>Projects</a>@endcan @endif
        @can('page', 'banking.index')<a href="{{ route('banking.index') }}" @class(['on' => request()->routeIs('banking.*')])>Banking</a>@endcan
        @can('page', 'payroll.index')<a href="{{ route('payroll.index') }}" @class(['on' => request()->routeIs('payroll.*')])>Payroll</a>@endcan
        @can('page', 'reports.index')<a href="{{ route('reports.index') }}" @class(['on' => request()->routeIs('reports.*')])>Reports</a>@endcan
        @can('page', 'settings.index')<a href="{{ route('settings.index') }}" @class(['on' => request()->routeIs('settings.*')])>Settings</a>@endcan
        @if (\App\Services\DataSharing\DataSharingService::enabled())@can('page', 'datasharing.index')<a href="{{ route('datasharing.index') }}" @class(['on' => request()->routeIs('datasharing.*')])>Data Sharing</a>@endcan @endif
        @if (auth()->user()->isAdmin())<a href="{{ route('admin.users') }}" @class(['on' => request()->routeIs('admin.*')])>Admin</a>@endif
        @endif
        <span class="sp"></span>
        <span class="co">{{ app(\App\Tenancy\TenantManager::class)->current()?->name }}</span>
        <a href="{{ route('companies.index') }}">Switch</a>
        <a href="{{ route('password.change') }}" title="Change your password">Password</a>
        <form class="inline" method="POST" action="{{ route('logout') }}">@csrf <button class="link">Sign out</button></form>
    </div>
</nav>
<div class="wide">
    @if (session('message'))<div class="flash ok">{{ session('message') }}</div>@endif
    @if (session('warning'))<div class="flash bad">{{ session('warning') }}</div>@endif
    @if (session('error'))<div class="flash bad">{{ session('error') }}</div>@endif
    @foreach (['party', 'ledger', 'group', 'entry', 'item', 'category', 'vehicle', 'prices', 'batch', 'lines', 'adjustment', 'sales', 'purchases', 'credit', 'banking', 'recurring', 'reminders', 'payroll', 'settings', 'projects'] as $k)@if ($errors->has($k))<div class="flash bad">{{ $errors->first($k) }}</div>@endif @endforeach
    @yield('content')
</div>
@endsection
