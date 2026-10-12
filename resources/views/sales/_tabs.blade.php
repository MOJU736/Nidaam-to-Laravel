@php
    $tabs = [['invoices.index', 'Invoices', ['invoices.*']], ['quotations.index', 'Quotations', ['quotations.*']], ['payments.index', 'Payments', ['payments.*']], ['credit-notes.index', 'Credit notes', ['credit-notes.*']], ['recurring.index', 'Recurring', ['recurring.*']], ['reminders.index', 'Reminders', ['reminders.*']]];
    if (auth()->user()->isAdmin()) { $tabs[] = ['sales.settings', 'Settings', ['sales.settings']]; $tabs[] = ['settings.email', 'Email', ['settings.email']]; $tabs[] = ['settings.templates', 'Templates', ['settings.templates', 'settings.templates.*']]; }
@endphp
<nav class="subnav" aria-label="Sales sections">
    @foreach ($tabs as [$route, $label, $match])
        <a class="{{ request()->routeIs(...$match) ? 'on' : '' }}" href="{{ route($route) }}">{{ $label }}</a>
    @endforeach
</nav>
