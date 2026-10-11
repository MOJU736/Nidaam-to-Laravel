@extends('layouts.main')
@section('title', 'Settings')
@section('content')
@include('settings._tabs')
<div class="bar"><h1 style="margin:0">Settings</h1></div>
@if ($s->account_locked)<div class="flash bad">This company is <strong>locked</strong>: nothing can be changed until it is unlocked.</div>@endif
@if ($s->lock_date)<div class="flash ok">The books are closed through <strong>{{ \App\Support\Money::date($s->lock_date) }}</strong>.</div>@endif
@php
    $groups = [
        '1. Your company' => [['settings.company', 'Company details', 'Name, address, currency, date format and financial year ('.\App\Support\Money::date($s->fy_start).' to '.\App\Support\Money::date($s->fy_end).')'],
            ['settings.templates', 'Templates & logo', 'The logo and how printed and emailed documents look'], ['settings.email', 'Email', 'Which address documents and reminders are sent from']],
        '2. Selling, buying and stock' => [['sales.settings', 'Sales settings', 'Accounts and numbering for sales'], ['purchases.settings', 'Purchase settings', 'Accounts and numbering for bills and expenses'],
            ['items.settings', 'Item settings', 'Inventory and cost of goods accounts'], ['settings.tax-rates', 'Tax rates', $taxRates.' named rate'.($taxRates === 1 ? '' : 's').' to pick on documents'],
            ['settings.payment-methods', 'Payment methods', 'The list offered when recording a payment']],
        '3. Journal entries' => [['settings.entry-types', 'Entry types', 'Numbering (prefix, suffix, padding) and account rules for each kind of entry'], ['settings.tags', 'Tags', 'Labels for grouping journal entries']],
        '4. Keep it safe' => [['settings.close-books', 'Close books', 'Stop changes to a finished month or year'], ['settings.year-end', 'Year-end close', 'Finish the financial year and start the next one'], ['settings.backup', 'Backup', 'Download a full copy of this company\'s data'], ['settings.lock', 'Lock company', 'Stop every change until it is unlocked']],
    ];
@endphp
@foreach ($groups as $title => $cards)
    <h2 style="margin:20px 0 8px">{{ $title }}</h2>
    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">
        @foreach ($cards as [$route, $label, $hint])
            @can('page', $route)
                <a href="{{ route($route) }}" class="card" style="text-decoration:none;color:inherit;padding:14px"><strong>{{ $label }}</strong><div class="muted" style="font-size:13px;margin-top:4px">{{ $hint }}</div></a>
            @endcan
        @endforeach
    </div>
@endforeach
@endsection
