@extends('layouts.app')
@section('body')
<nav class="top">
    <div class="in">
        @can('page', 'dashboard')<a href="{{ route('dashboard') }}" @class(['on' => request()->routeIs('dashboard')])>Dashboard</a>@endcan
        @can('page', 'entries.index')<a href="{{ route('entries.index') }}" @class(['on' => request()->routeIs('entries.*')])>Entries</a>@endcan
        @can('page', 'chart')<a href="{{ route('chart') }}" @class(['on' => request()->routeIs('chart', 'ledgers.*', 'groups.*')])>Accounts</a>@endcan
        @can('page', 'invoices.index')<a href="{{ route('invoices.index') }}" @class(['on' => request()->routeIs('invoices.*', 'quotations.*', 'payments.*', 'sales.*', 'credit-notes.*', 'recurring.*', 'reminders.*')])>Sales</a>@endcan
        @can('page', 'bills.index')<a href="{{ route('bills.index') }}" @class(['on' => request()->routeIs('bills.*', 'purchase-orders.*', 'vendor-payments.*', 'expenses.*', 'purchases.*', 'supplier-credits.*')])>Purchases</a>@endcan
        @can('page', 'items.index')<a href="{{ route('items.index') }}" @class(['on' => request()->routeIs('items.*')])>Items</a>@endcan
        @can('page', 'customers.index')<a href="{{ route('customers.index') }}" @class(['on' => request()->routeIs('customers.*')])>Customers</a>@endcan
        @can('page', 'vendors.index')<a href="{{ route('vendors.index') }}" @class(['on' => request()->routeIs('vendors.*')])>Vendors</a>@endcan
        @can('page', 'banking.index')<a href="{{ route('banking.index') }}" @class(['on' => request()->routeIs('banking.*')])>Banking</a>@endcan
        @can('page', 'reports.index')<a href="{{ route('reports.index') }}" @class(['on' => request()->routeIs('reports.*')])>Reports</a>@endcan
        @if (auth()->user()->isAdmin())<a href="{{ route('admin.users') }}" @class(['on' => request()->routeIs('admin.*')])>Admin</a>@endif
        <span class="sp"></span>
        <span class="co">{{ app(\App\Tenancy\TenantManager::class)->current()?->name }}</span>
        <a href="{{ route('companies.index') }}">Switch</a>
        <form class="inline" method="POST" action="{{ route('logout') }}">@csrf <button class="link">Sign out</button></form>
    </div>
</nav>
<div class="wide">
    @if (session('message'))<div class="flash ok">{{ session('message') }}</div>@endif
    @if (session('warning'))<div class="flash bad">{{ session('warning') }}</div>@endif
    @if (session('error'))<div class="flash bad">{{ session('error') }}</div>@endif
    @foreach (['party', 'ledger', 'group', 'entry', 'item', 'category', 'vehicle', 'prices', 'batch', 'lines', 'adjustment', 'sales', 'purchases', 'credit', 'banking', 'recurring', 'reminders'] as $k)@if ($errors->has($k))<div class="flash bad">{{ $errors->first($k) }}</div>@endif @endforeach
    @yield('content')
</div>
@endsection
