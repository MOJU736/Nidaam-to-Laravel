@php
    $tabs = [['invoices.index', 'Invoices', ['invoices.*']], ['quotations.index', 'Quotations', ['quotations.*']], ['payments.index', 'Payments', ['payments.*']], ['credit-notes.index', 'Credit notes', ['credit-notes.*']], ['recurring.index', 'Recurring', ['recurring.*']]];
    if (auth()->user()->isAdmin()) { $tabs[] = ['sales.settings', 'Settings', ['sales.settings']]; $tabs[] = ['settings.email', 'Email', ['settings.email']]; }
@endphp
<div class="actions" style="margin-bottom:16px;border-bottom:1px solid var(--line);padding-bottom:8px">
    @foreach ($tabs as [$route, $label, $match])
        <a class="btn sm {{ request()->routeIs(...$match) ? '' : 'ghost' }}" href="{{ route($route) }}">{{ $label }}</a>
    @endforeach
</div>
