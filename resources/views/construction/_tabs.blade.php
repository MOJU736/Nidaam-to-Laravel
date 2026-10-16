@php $tabs = [['projects.index', 'Projects', ['projects.*']], ['construction.workers', 'Workers', ['construction.workers*']], ['construction.wages', 'Wage sheets', ['construction.wages*']], ['construction.stock', 'Site stock', ['construction.stock*']], ['construction.rates', 'Rate list', ['construction.rates*']]]; @endphp
<nav class="subnav" aria-label="Construction sections">
    @foreach ($tabs as [$route, $label, $match])<a class="{{ request()->routeIs(...$match) ? 'on' : '' }}" href="{{ route($route) }}">{{ $label }}</a>@endforeach
</nav>