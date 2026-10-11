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
        ['How is the business doing?', [
            ['reports.profit-loss', 'Profit & Loss', 'Income minus expenses for a period'],
            ['reports.pl-by-month', 'P&L by Month', 'Income and expenses month by month, side by side'],
            ['reports.balance-sheet', 'Balance Sheet', 'What the business owns and owes on a date'],
            ['reports.cash-flow', 'Statement of Cash Flows', 'Where the cash came from and where it went'],
            ['reports.pl-compare', 'P&L Comparison', 'This period beside the one before, or last year'],
            ['reports.pl-by-project', 'P&L by Project', 'Income and expenses for each project (Construction)'],
            ['reports.bs-compare', 'Balance Sheet Comparison', 'Two dates side by side, with the change'],
            ['reports.profit-by-item', 'Profit by Item', 'What each item sold for, cost and left'],
            ['reports.payments-received', 'Payments Received', 'Customer payments by how paid and where they went'],
        ]],
        ['Who owes you', [
            ['reports.ar-aging', 'A/R Aging', "Customers' unpaid invoices, by how late they are"],
            ['reports.open-invoices', 'Open Invoices', 'Every invoice not fully paid, by customer'],
            ['reports.customer-balance', 'Customer Balance', "Each customer's balance"],
        ]],
        ['Who you owe', [
            ['reports.ap-aging', 'A/P Aging', 'Unpaid bills, by how late they are'],
            ['reports.unpaid-bills', 'Unpaid Bills', 'Every bill not fully paid, by vendor'],
            ['reports.vendor-balance', 'Vendor Balance', "Each vendor's balance"],
        ]],
        ['What you sold and bought', [
            ['reports.sales-by-customer', 'Sales by Customer', 'How much each customer bought'],
            ['reports.sales-by-item', 'Sales by Item', 'How much of each item you sold'],
            ['reports.purchases-by-vendor', 'Purchases by Vendor', 'How much you bought from each vendor'],
            ['reports.purchases-by-item', 'Purchases by Item', 'How much of each item you bought'],
            ['reports.tax-summary', 'Tax Summary', 'Tax charged on sales and paid on purchases'],
        ]],
        ['Customers and items', [
            ['reports.customer-items', 'Customer Items Statement', 'Every item a customer took, with invoice numbers (can be emailed)'],
        ]],
        ['Stock', [
            ['reports.stock-expiry', 'Stock Expiry', 'Batches expired or expiring soon'],
            ['reports.inventory-valuation', 'Inventory Valuation', 'Quantity on hand and value of every stock item'],
            ['reports.reorder', 'Reorder', 'Items at or below their reorder level'],
        ]],
        ['Check the books', [
            ['reports.health', 'Books Health Check', 'Finds what makes a report look wrong, and what to do about it'],
            ['reports.trial-balance', 'Trial Balance', "Every account's balance on one page"],
            ['reports.trial-balance', 'Trial Balance (detail)', 'Opening balance, debits, credits and closing balance of every account', ['view' => 'detail']],
            ['reports.general-ledger', 'General Ledger', 'Every account that moved, with its transactions and running balance'],
            ['reports.journal', 'Journal', 'Every transaction and the accounts it went to'],
        ]],
    ];
@endphp
<div class="bar"><h1 style="margin:0">Reports</h1></div>
@foreach ($sections as [$heading, $cards])
    <h3 style="margin:22px 0 8px">{{ $heading }}</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px">
        @foreach ($cards as $card)
            @php [$route, $name, $hint] = $card; $q = $card[3] ?? []; @endphp
            <a class="card" href="{{ route($route, $q ?? []) }}" style="text-decoration:none;color:inherit;display:block"><strong>{{ $name }}</strong><div class="muted" style="font-size:13px;margin-top:4px">{{ $hint }}</div></a>
        @endforeach
    </div>
@endforeach

@endsection
