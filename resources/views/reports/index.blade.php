@extends('layouts.main')
@section('title', 'Reports')
@section('content')
@php
    $construction = \App\Support\Money::constructionEnabled();
    $commissions = \App\Services\Commissions\CommissionService::enabled();
    $viewer = app(\App\Services\Auth\Permissions::class)->groupName(auth()->user()) === \App\Services\Auth\Permissions::RESTRICTED;
    $sections = [
        ['Most used', 'Start here', [
            ['reports.profit-loss', 'Profit & Loss', 'Did we make money? Income minus expenses for a period'],
            ['reports.balance-sheet', 'Balance Sheet', 'What the business owns and owes on a date'],
            ['reports.ar-aging', 'Who Owes You', "Customers' unpaid invoices, by how late they are"],
            ['reports.statement', 'Account Statement', 'Every movement in one account (e.g. your bank)'],
        ]],
        ['How is the business doing?', 'Profit, worth and cash', [
            ['reports.pl-by-month', 'P&L by Month', 'Income and expenses month by month, side by side'],
            $construction ? ['reports.pl-by-project', 'P&L by Project', 'Profit of each project'] : null,
            ['reports.cash-flow', 'Statement of Cash Flows', 'Where the cash came from and where it went'],
            ['reports.pl-compare', 'P&L Comparison', 'This period next to last year or the period before'],
            ['reports.bs-compare', 'Balance Sheet Comparison', 'What you own and owe now, next to a year ago'],
            ['reports.profit-by-item', 'Profit by Item', 'What each item sold for, cost and left as profit'],
        ]],
        ['Who owes you', 'Customers', [
            ['reports.open-invoices', 'Open Invoices', 'Every invoice not fully paid, by customer'],
            ['reports.customer-balance', 'Customer Balance', "Each customer's balance"],
            ['reports.customer-items', 'Customer Items Statement', 'Every item each customer took, with invoice numbers (can be emailed)'],
            ['reports.payments-received', 'Payments Received', 'Money in from customers, by cash, bank or e-wallet'],
        ]],
        ['Who you owe', 'Vendors', [
            ['reports.ap-aging', 'What You Owe', 'Unpaid bills, by how late they are'],
            ['reports.unpaid-bills', 'Unpaid Bills', 'Every bill not fully paid, by vendor'],
            ['reports.vendor-balance', 'Vendor Balance', "Each vendor's balance"],
        ]],
        ['What you sold and bought', 'Totals by customer, vendor and item', [
            ['reports.sales-by-customer', 'Sales by Customer', 'How much each customer bought'],
            ['reports.sales-by-item', 'Sales by Item', 'How much of each item you sold'],
            ['reports.purchases-by-vendor', 'Purchases by Vendor', 'How much you bought from each vendor'],
            ['reports.purchases-by-item', 'Purchases by Item', 'How much of each item you bought'],
            ['reports.tax-summary', 'Tax Summary', 'Tax charged on sales and paid on purchases'],
            $commissions ? ['reports.commissions', 'Commissions', 'Received, paid out and still owed, per agent'] : null,
        ]],
        ['Stock', 'What you have and what to order', [
            ['reports.inventory-valuation', 'Inventory Valuation', 'Quantity on hand and value of every item'],
            ['reports.reorder', 'Reorder', 'Items at or below their reorder level'],
            ['reports.stock-expiry', 'Stock Expiry', 'Batches expired or expiring soon'],
        ]],
        ['Check the books', 'For you and your accountant', [
            ['reports.health', 'Books Health Check', 'Finds what makes reports look wrong, and how to fix it'],
            ['reports.trial-balance', 'Trial Balance', "Every account's balance on one page"],
            ['reports.trial-balance', 'Trial Balance (detail)', 'Opening balance, debits, credits and closing balance of every account', ['view' => 'detail']],
            ['reports.general-ledger', 'General Ledger', 'Every account and every transaction, for your accountant'],
            ['reports.journal', 'Journal', 'Every transaction and the accounts it went to'],
            ! $viewer ? ['banking.index', 'Reconcile', 'Match your records with a bank or cash statement'] : null,
        ]],
    ];
@endphp
<div class="bar"><div><h1>Reports</h1><div class="muted">Grouped by the question each one answers</div></div>
    <input id="rsearch" class="find" type="search" placeholder="Find a report: profit, who owes, stock, tax..." autocomplete="off" style="width:340px;max-width:100%"></div>
<div class="muted" id="rnone" hidden style="margin:12px 0">No report matches that. Try another word.</div>
@foreach ($sections as [$heading, $hint, $cards])
    <div class="rsec">
        <h3 class="sec" style="margin-top:26px">{{ $heading }} <span style="text-transform:none;letter-spacing:0;font-weight:400">· {{ $hint }}</span></h3>
        <div class="linkgrid">
            @foreach (array_filter($cards) as $card)
                @php [$route, $name, $desc] = $card; $q = $card[3] ?? []; @endphp
                @can('page', $route)
                <a class="linkcard rcard {{ $loop->parent->first ? 'hot' : '' }}" data-text="{{ strtolower($name.' '.$desc.' '.$heading) }}" href="{{ route($route, $q) }}"><span><strong>{{ $name }}</strong><small>{{ $desc }}</small></span>{!! \App\Support\Icon::svg('chevron', 16) !!}</a>
                @endcan
            @endforeach
        </div>
    </div>
@endforeach
<script>
(function () {
    var box = document.getElementById('rsearch'), none = document.getElementById('rnone');
    box.addEventListener('input', function () {
        var words = box.value.toLowerCase().split(/\s+/).filter(Boolean), shown = 0;
        document.querySelectorAll('.rcard').forEach(function (c) { var t = c.getAttribute('data-text'), ok = words.every(function (w) { return t.indexOf(w) !== -1; }); c.style.display = ok ? '' : 'none'; if (ok) { shown++; } });
        document.querySelectorAll('.rsec').forEach(function (s) { var any = Array.prototype.some.call(s.querySelectorAll('.rcard'), function (c) { return c.style.display !== 'none'; }); s.style.display = any ? '' : 'none'; });
        none.hidden = shown > 0;
    });
})();
</script>
@endsection