@php
    $tabs = [['settings.index', 'Home', ['settings.index']], ['settings.company', 'Company', ['settings.company*']], ['settings.tax-rates', 'Tax rates', ['settings.tax-rates*']], ['settings.payment-methods', 'Payment methods', ['settings.payment-methods*']],
        ['settings.entry-types', 'Entry types', ['settings.entry-types*']], ['settings.tags', 'Tags', ['settings.tags*']], ['settings.email', 'Email', ['settings.email*']], ['settings.templates', 'Templates & logo', ['settings.templates*']],
        ['settings.close-books', 'Close books', ['settings.close-books*']], ['settings.year-end', 'Year-end close', ['settings.year-end*']], ['settings.backup', 'Backup', ['settings.backup*']], ['settings.quickbooks', 'From QuickBooks', ['settings.quickbooks*']],['settings.lock', 'Lock company', ['settings.lock*']]];
@endphp
<nav class="subnav" aria-label="Settings sections">
    @foreach ($tabs as [$route, $label, $match])
        @can('page', $route)<a class="{{ request()->routeIs(...$match) ? 'on' : '' }}" href="{{ route($route) }}">{{ $label }}</a>@endcan
    @endforeach
</nav>