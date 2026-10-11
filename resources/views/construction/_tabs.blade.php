@php $tabs = [['projects.index', 'Projects', ['projects.*']], ['construction.workers', 'Workers', ['construction.workers*']], ['construction.wages', 'Wage sheets', ['construction.wages*']], ['construction.stock', 'Site stock', ['construction.stock*']], ['construction.rates', 'Rate list', ['construction.rates*']]]; @endphp
<div class="actions" style="margin-bottom:16px;border-bottom:1px solid var(--line);padding-bottom:8px">
    @foreach ($tabs as [$route, $label, $match])<a class="btn sm {{ request()->routeIs(...$match) ? '' : 'ghost' }}" href="{{ route($route) }}">{{ $label }}</a>@endforeach
</div>
