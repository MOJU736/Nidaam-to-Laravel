@extends('layouts.main')
@section('title', 'Reports')
@section('content')
@php
    $sections = [
        ['Most used', [
            ['reports.profit-loss', 'Profit & Loss', 'Did we make money? Income minus expenses for a period'],
            ['reports.balance-sheet', 'Balance Sheet', 'What the business owns and owes on a date'],
            ['reports.ar-aging', 'Who Owes You', "Customers' unpaid invoices, by how late they are"],
            ['reports.statement', 'Account Statement', 'Every movement in one account (e.g. your bank)'],
        ]],
        ['Who owes you', [
            ['reports.ar-aging', 'A/R Aging', "Customers' unpaid invoices, by how late they are"],
            ['reports.customer-balance', 'Customer Balance', "Each customer's balance"],
        ]],
        ['Who you owe', [
            ['reports.ap-aging', 'A/P Aging', 'Unpaid bills, by how late they are'],
            ['reports.vendor-balance', 'Vendor Balance', "Each vendor's balance"],
        ]],
        ['Check the books', [
            ['reports.trial-balance', 'Trial Balance', "Every account's balance on one page"],
            ['reports.journal', 'Journal', 'Every transaction and the accounts it went to'],
        ]],
    ];
@endphp
<div class="bar"><h1 style="margin:0">Reports</h1></div>
@foreach ($sections as [$heading, $cards])
    <h3 style="margin:22px 0 8px">{{ $heading }}</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px">
        @foreach ($cards as [$route, $name, $hint])
            <a class="card" href="{{ route($route) }}" style="text-decoration:none;color:inherit;display:block"><strong>{{ $name }}</strong><div class="muted" style="font-size:13px;margin-top:4px">{{ $hint }}</div></a>
        @endforeach
    </div>
@endforeach
<p class="muted" style="margin-top:24px">More reports (sales and purchases by item, tax summary, cash flow, inventory valuation, comparisons) come in a later step.</p>
@endsection
