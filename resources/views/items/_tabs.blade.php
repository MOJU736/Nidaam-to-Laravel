@php
    $tabs = [['items.index', 'Items', ['items.index', 'items.create', 'items.edit', 'items.batches']], ['items.categories', 'Categories', ['items.categories']], ['items.adjustments', 'Stock adjustments', ['items.adjustments', 'items.adjustments.*']]];
    if (\App\Support\Money::vehiclesEnabled() && auth()->user()->isAdmin()) { $tabs[] = ['items.vehicles', 'Vehicle pricing', ['items.vehicles', 'items.vehicles.*']]; }
    if (auth()->user()->isAdmin()) { $tabs[] = ['items.settings', 'Settings', ['items.settings']]; }
@endphp
<nav class="subnav" aria-label="Items sections">
    @foreach ($tabs as [$route, $label, $match])
        <a class="{{ request()->routeIs(...$match) ? 'on' : '' }}" href="{{ route($route) }}">{{ $label }}</a>
    @endforeach
</nav>
