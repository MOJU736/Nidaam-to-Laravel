@extends('layouts.main')
@section('title', 'Settings')
@section('content')
@include('settings._tabs')
<div class="bar"><div><h1>Settings</h1><div class="muted">Everything that shapes how this company works</div></div></div>
@if ($s->account_locked)<div class="flash bad">This company is <strong>locked</strong>: nothing can be changed until it is unlocked.</div>@endif
@if ($s->lock_date)<div class="flash ok">The books are closed through <strong>{{ \App\Support\Money::date($s->lock_date) }}</strong>.</div>@endif
@php
    $groups = [
        'Your company' => [['settings.company', 'Company details', 'Name, address, currency, date format and financial year ('.\App\Support\Money::date($s->fy_start).' to '.\App\Support\Money::date($s->fy_end).')'],
            ['settings.templates', 'Templates & logo', 'The logo and how printed and emailed documents look'], ['settings.email', 'Email', 'Which address documents and reminders are sent from']],
        'Selling, buying and stock' => [['sales.settings', 'Sales settings', 'Accounts and numbering for sales'], ['purchases.settings', 'Purchase settings', 'Accounts and numbering for bills and expenses'],
            ['items.settings', 'Item settings', 'Inventory and cost of goods accounts'], ['settings.tax-rates', 'Tax rates', $taxRates.' named rate'.($taxRates === 1 ? '' : 's').' to pick on documents'],
            ['settings.payment-methods', 'Payment methods', 'The list offered when recording a payment']],
        'Journal entries' => [['settings.entry-types', 'Entry types', 'Numbering (prefix, suffix, padding) and account rules for each kind of entry'], ['settings.tags', 'Tags', 'Labels for grouping journal entries']],
        'Keep it safe' => [['settings.close-books', 'Close books', 'Stop changes to a finished month or year'], ['settings.year-end', 'Year-end close', 'Finish the financial year and start the next one'], ['settings.backup', 'Backup', 'Download a full copy of this company\'s data'], ['settings.quickbooks', 'Move from QuickBooks', 'Bring in balances, open invoices, bills and stock'],['settings.lock', 'Lock company', 'Stop every change until it is unlocked']],
    ];
@endphp
@foreach ($groups as $title => $cards)
    <h3 class="sec" style="margin-top:26px">{{ $title }}</h3>
    <div class="linkgrid">
        @foreach ($cards as [$route, $label, $hint])
            @can('page', $route)
                <a href="{{ route($route) }}" class="linkcard"><span><strong>{{ $label }}</strong><small>{{ $hint }}</small></span>{!! \App\Support\Icon::svg('chevron', 16) !!}</a>
            @endcan
        @endforeach
    </div>
@endforeach
@endsection