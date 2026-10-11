@php
    $tabs = [['bills.index', 'Bills', ['bills.*']], ['purchase-orders.index', 'Purchase orders', ['purchase-orders.*']], ['vendor-payments.index', 'Payments', ['vendor-payments.*']], ['supplier-credits.index', 'Supplier credits', ['supplier-credits.*']], ['expenses.index', 'Quick expenses', ['expenses.*']]];
    if (auth()->user()->isAdmin()) { $tabs[] = ['purchases.settings', 'Settings', ['purchases.settings']]; }
@endphp
<div class="actions" style="margin-bottom:16px;border-bottom:1px solid var(--line);padding-bottom:8px">
    @foreach ($tabs as [$route, $label, $match])
        <a class="btn sm {{ request()->routeIs(...$match) ? '' : 'ghost' }}" href="{{ route($route) }}">{{ $label }}</a>
    @endforeach
</div>
