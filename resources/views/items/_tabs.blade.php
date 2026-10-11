@php
    $tabs = [['items.index', 'Items', ['items.index', 'items.create', 'items.edit', 'items.batches']], ['items.categories', 'Categories', ['items.categories']], ['items.adjustments', 'Stock adjustments', ['items.adjustments', 'items.adjustments.*']]];
    if (\App\Support\Money::vehiclesEnabled() && auth()->user()->isAdmin()) { $tabs[] = ['items.vehicles', 'Vehicle pricing', ['items.vehicles', 'items.vehicles.*']]; }
    if (auth()->user()->isAdmin()) { $tabs[] = ['items.settings', 'Settings', ['items.settings']]; }
@endphp
<div class="actions" style="margin-bottom:16px;border-bottom:1px solid var(--line);padding-bottom:8px">
    @foreach ($tabs as [$route, $label, $match])
        <a class="btn sm {{ request()->routeIs(...$match) ? '' : 'ghost' }}" href="{{ route($route) }}">{{ $label }}</a>
    @endforeach
</div>
