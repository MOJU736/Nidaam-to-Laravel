@php
    $tabs = [['bills.index', 'Bills', ['bills.*']], ['purchase-orders.index', 'Purchase orders', ['purchase-orders.*']], ['vendor-payments.index', 'Payments', ['vendor-payments.*']], ['supplier-credits.index', 'Supplier credits', ['supplier-credits.*']], ['expenses.index', 'Quick expenses', ['expenses.*']]];
    if (auth()->user()->isAdmin()) { $tabs[] = ['purchases.settings', 'Settings', ['purchases.settings']]; }
@endphp
<nav class="subnav" aria-label="Purchases sections">
    @foreach ($tabs as [$route, $label, $match])
        <a class="{{ request()->routeIs(...$match) ? 'on' : '' }}" href="{{ route($route) }}">{{ $label }}</a>
    @endforeach
</nav>