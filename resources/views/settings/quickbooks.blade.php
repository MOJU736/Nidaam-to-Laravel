@extends('layouts.main')
@section('title', 'Move from QuickBooks')
@section('content')
@include('settings._tabs')
@php $m = fn ($v) => (\App\Support\Money::cmp($v, '0') < 0 ? '-' : '').\App\Support\Money::format(\App\Support\Money::cmp($v, '0') < 0 ? \App\Support\Money::neg($v) : $v); @endphp
<div class="bar"><div><h1 style="margin:0">Move from QuickBooks</h1><div class="muted">Bring a QuickBooks company's opening balances, unpaid invoices, unpaid bills and stock into this company in one go.</div></div></div>
@foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach

@if ($done)
    <div class="card" style="max-width:760px">
        <h2 style="margin-top:0">Already brought in</h2>
        <p>On {{ \App\Support\Money::date(substr($done['at'], 0, 10)) }} the QuickBooks balances as at <strong>{{ \App\Support\Money::date($done['as_of']) }}</strong> were brought in:
        {{ $done['accounts'] }} accounts, {{ $done['customers'] }} customers with {{ $done['invoices'] }} open invoices ({{ $m($done['ar']) }}), {{ $done['vendors'] }} vendors with {{ $done['bills'] }} open bills ({{ $m($done['ap']) }}) and {{ $done['items'] }} items (stock {{ $m($done['stock']) }}).</p>
        <p class="muted">This can only be done once for a company. Check it under Reports → Trial Balance.</p>
    </div>
@else
    @if ($existing)
        <div class="flash bad" style="max-width:760px">This company already has {{ collect($existing)->map(fn ($n, $l) => "$n $l")->implode(', ') }}. The move only goes into a new, empty company: create one under Admin → Companies and keep this one as it is.</div>
    @endif
    <div class="card" style="max-width:760px;margin-bottom:16px">
        <h2 style="margin-top:0">1. Upload the opening balances workbook</h2>
        <p class="muted" style="font-size:13px">An Excel file (.xlsx) prepared from the QuickBooks reports, with these sheets (the first row of each is its headings):</p>
        <ul class="muted" style="font-size:13px;margin-top:0">
            <li><strong>Start here</strong>: "Opening date" in column A, the date in column B (inside this company's financial year).</li>
            <li><strong>Accounts</strong>: QuickBooks account · Debit · Credit · Nidaam account · Nidaam group · New group goes under · Bank or cash? Use <code>(customers)</code>, <code>(vendors)</code> and <code>(items)</code> as the Nidaam account on the Accounts Receivable, Accounts Payable and Inventory lines.</li>
            <li><strong>Customers</strong>: Customer · Phone · Email · Address · Balance in QuickBooks</li>
            <li><strong>Open invoices</strong>: Customer · Nidaam invoice number · Date · Due date · Amount still owed · Note</li>
            <li><strong>Vendors</strong>: Vendor · Phone · Email · Address · Balance in QuickBooks</li>
            <li><strong>Open bills</strong>: Vendor · Vendor reference · Date · Due date · Amount still owed · Note</li>
            <li><strong>Items</strong>: Item · Quantity on hand · Stock value and/or Unit cost · Sales price</li>
        </ul>
        <form method="POST" action="{{ route('settings.quickbooks') }}" enctype="multipart/form-data">@csrf
            <input type="file" name="workbook" accept=".xlsx" required>
            <div class="actions" style="margin-top:10px"><button @disabled($existing)>Check the workbook</button><span class="muted" style="font-size:13px">Nothing is saved yet: this only reads the file and shows what would happen.</span></div>
        </form>
    </div>

    @if ($plan)
        <div class="card" style="max-width:900px">
            <h2 style="margin-top:0">2. Check it · <span class="muted" style="font-size:14px;font-weight:400">{{ $fileName }}</span></h2>
            @if ($plan['checks'])
                <table class="list"><thead><tr><th>Check</th><th class="num">QuickBooks</th><th class="num">Workbook</th><th></th></tr></thead><tbody>
                @foreach ($plan['checks'] as [$label, $qb, $file, $ok])
                    <tr><td>{{ $label }}</td><td class="num">{{ $m($qb) }}</td><td class="num">{{ $m($file) }}</td><td>{!! $ok ? '<span style="color:var(--ok,green)">✔ agrees</span>' : '<span style="color:var(--danger)">✘ does not agree</span>' !!}</td></tr>
                @endforeach
                </tbody></table>
            @endif

            @if ($plan['errors'])
                <h3 style="color:var(--danger)">Fix these first ({{ count($plan['errors']) }})</h3>
                <ul style="color:var(--danger)">@foreach (array_slice($plan['errors'], 0, 60) as $e)<li>{{ $e }}</li>@endforeach @if (count($plan['errors']) > 60)<li>… and {{ count($plan['errors']) - 60 }} more.</li>@endif</ul>
                <p class="muted">Change the workbook and upload it again.</p>
            @endif
            @if ($plan['warnings'])
                <h3>Worth reading</h3>
                <ul class="muted">@foreach (array_slice($plan['warnings'], 0, 40) as $w)<li>{{ $w }}</li>@endforeach</ul>
            @endif

            @if (! $plan['errors'])
                <h3>What will be saved, dated {{ \App\Support\Money::date($plan['opening_date']) }}</h3>
                <ul>
                    @if ($plan['groups_new'])<li>{{ count($plan['groups_new']) }} new account group{{ count($plan['groups_new']) === 1 ? '' : 's' }}: {{ collect($plan['groups_new'])->map(fn ($g) => $g['name'].' (under '.$g['parent'].')')->implode(', ') }}</li>@endif
                    <li>{{ count($plan['accounts']) }} accounts ({{ collect($plan['accounts'])->where('ledger_id', null)->count() }} new), with one Journal entry of {{ count($plan['lines']) + 1 }} lines for their balances</li>
                    <li>{{ count($plan['customers']) }} customers and {{ count($plan['invoices']) }} issued invoices for {{ $m($plan['totals']['ar']) }}</li>
                    <li>{{ count($plan['vendors']) }} vendors and {{ count($plan['bills']) }} issued bills for {{ $m($plan['totals']['ap']) }}</li>
                    <li>{{ count($plan['items']) }} items with stock worth {{ $m($plan['totals']['stock']) }}</li>
                    <li>"Opening Balance Equity" is the other side of everything and comes back to zero.</li>
                </ul>
                <p class="muted" style="font-size:13px">Everything goes in together or not at all, and it can only be done once.</p>
                <div class="actions">
                    <form method="POST" action="{{ route('settings.quickbooks.import') }}" onsubmit="return confirm('Bring these balances in now? This can only be done once for this company.')">@csrf<button>Import now</button></form>
                    <form method="POST" action="{{ route('settings.quickbooks.cancel') }}">@csrf<button class="ghost">Cancel</button></form>
                </div>
            @else
                <form method="POST" action="{{ route('settings.quickbooks.cancel') }}">@csrf<button class="ghost">Cancel</button></form>
            @endif
        </div>
    @endif
@endif
@endsection
