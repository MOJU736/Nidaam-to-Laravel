@extends('layouts.app')
@section('body')
<nav class="top">
    <div class="in">
        <a href="{{ route('dashboard') }}" @class(['on' => request()->routeIs('dashboard')])>Dashboard</a>
        <a href="{{ route('entries.index') }}" @class(['on' => request()->routeIs('entries.*')])>Entries</a>
        <a href="{{ route('chart') }}" @class(['on' => request()->routeIs('chart', 'ledgers.*', 'groups.*')])>Accounts</a>
        <a href="{{ route('invoices.index') }}" @class(['on' => request()->routeIs('invoices.*', 'quotations.*', 'payments.*', 'sales.*')])>Sales</a>
        <a href="{{ route('bills.index') }}" @class(['on' => request()->routeIs('bills.*', 'purchase-orders.*', 'vendor-payments.*', 'expenses.*', 'purchases.*')])>Purchases</a>
        <a href="{{ route('items.index') }}" @class(['on' => request()->routeIs('items.*')])>Items</a>
        <a href="{{ route('customers.index') }}" @class(['on' => request()->routeIs('customers.*')])>Customers</a>
        <a href="{{ route('vendors.index') }}" @class(['on' => request()->routeIs('vendors.*')])>Vendors</a>
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
    @foreach (['party', 'ledger', 'group', 'entry', 'item', 'category', 'vehicle', 'prices', 'batch', 'lines', 'adjustment', 'sales', 'purchases'] as $k)@if ($errors->has($k))<div class="flash bad">{{ $errors->first($k) }}</div>@endif @endforeach
    @yield('content')
</div>
@endsection
