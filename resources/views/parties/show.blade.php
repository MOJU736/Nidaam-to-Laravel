@extends('layouts.main')
@section('title', $party->name)
@section('content')
@php
    use App\Support\Money;
    $closingSigned = Money::signed($closing, $cfg['normal']);
    $openingSigned = Money::signed($opening, $cfg['normal']);
    $bal = fn ($dc, $amt) => Money::format($dc === $cfg['normal'] ? $amt : Money::neg($amt));
    $typeNames = ['document' => $cfg['side'] === 'ar' ? 'Invoice' : 'Bill', 'payment' => 'Payment', 'credit_note' => 'Credit note', 'refund' => 'Refund', 'other' => 'Journal'];
@endphp
<a class="back" href="{{ route($cfg['route'].'.index') }}">&larr; {{ $cfg['many'] }}</a>
<div class="bar">
    <div class="titlerow"><span class="avs lg">{{ mb_strtoupper(mb_substr(trim($party->name), 0, 1)) }}</span><h1>{{ $party->name }}</h1>@unless ($party->status)<span class="pill">Inactive</span>@endunless</div>
    <div class="actions">
        @if ($cfg['side'] === 'ar')<a class="btn" href="{{ route('payments.create', $party->id) }}">Receive payment</a><a class="btn ghost" href="{{ route('invoices.create', $party->id) }}">New invoice</a>@else<a class="btn" href="{{ route('vendor-payments.create', $party->id) }}">Pay vendor</a><a class="btn ghost" href="{{ route('bills.create', $party->id) }}">New bill</a>@endif
        <details class="menu">
            <summary>More <svg class="ic" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></summary>
            <div class="drop">
                @if ($cfg['side'] === 'ar')
                    @if ($closing['dc'] === 'D' && Money::positive($closing['amount']))<button type="button" onclick="var e=document.getElementById('reminderBox');if(e){e.hidden=!e.hidden;if(!e.hidden)e.scrollIntoView({behavior:'smooth',block:'center'})}">Send reminder</button>@endif
                    <a href="{{ route('quotations.create', $party->id) }}">New quotation</a>
                @else
                    <a href="{{ route('purchase-orders.create', $party->id) }}">New purchase order</a>
                @endif
                <hr><a href="{{ route($cfg['route'].'.edit', $party->id) }}">Edit details</a>
            </div>
        </details>
    </div>
</div>

<div class="docgrid">
    <div class="card docmeta">
        <div><small>Contact</small>{{ $party->contact_person ?: '—' }}</div>
        <div><small>Phone</small>{{ $party->phone ?: '—' }}</div>
        <div><small>Email</small>{{ $party->email ?: '—' }}</div>
        <div class="wide2"><small>Address</small>{!! nl2br(e($party->address ?: '—')) !!}</div>
        @if ($party->notes)<div class="wide2"><small>Notes</small>{!! nl2br(e($party->notes)) !!}</div>@endif
    </div>
    @php $owes = Money::positive($closing['amount']); @endphp
    <div class="card balance {{ $owes ? '' : 'settled' }}">
        <small>{{ $owes ? ($cfg['side'] === 'ar' ? 'Owes you' : 'You owe') : 'All settled' }}</small>
        <div class="big">{{ Money::format($closingSigned) }}</div>
        <dl><dt>Opening balance</dt><dd>{{ Money::format($openingSigned) }}</dd><dt>{{ $startdate || $enddate ? 'In this period' : 'Transactions' }}</dt><dd>{{ count($transactions) }}</dd></dl>
    </div>
</div>
@if ($vehicles->isNotEmpty())
    <h2>Vehicles</h2>
    <div class="card tbl"><table>
        <thead><tr><th>Model</th><th>Plate #</th></tr></thead>
        <tbody>@foreach ($vehicles as $veh)<tr><td>{{ $veh->model }}</td><td>{{ $veh->plate_number }}</td></tr>@endforeach</tbody>
    </table></div>
@endif

<div class="bar" style="margin-top:0"><h2 style="margin:0">Statement</h2><div class="actions">@include('documents._actions', ['type' => $cfg['side'] === 'ar' ? 'customer-statement' : 'vendor-statement', 'id' => $party->id, 'opts' => array_filter(['startdate' => $startdate ? date('Y-m-d', strtotime($startdate)) : null, 'enddate' => $enddate ? date('Y-m-d', strtotime($enddate)) : null])])</div></div>
<form method="GET" class="actions" style="margin-bottom:12px;align-items:end">
    <div><label for="startdate" style="margin-top:0">From</label><input id="startdate" type="date" name="startdate" value="{{ $startdate ? date('Y-m-d', strtotime($startdate)) : '' }}"></div>
    <div><label for="enddate" style="margin-top:0">To</label><input id="enddate" type="date" name="enddate" value="{{ $enddate ? date('Y-m-d', strtotime($enddate)) : '' }}"></div>
    <button class="sm" style="margin-bottom:2px">Filter</button>
    @if ($startdate || $enddate)<a class="btn ghost sm" style="margin-bottom:2px" href="{{ route($cfg['route'].'.show', $party->id) }}">Clear</a>@endif
</form>

<div class="card tbl">
    <table>
        <thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Narration</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
        <tbody>
            <tr class="muted"><td colspan="6">Opening balance</td><td class="num">{{ Money::format($openingSigned) }}</td></tr>
            @forelse ($transactions as $t)
                <tr>
                    <td>{{ Money::date($t['date']) }}</td>
                    <td>{{ $typeNames[$t['type']] ?? $t['type'] }}</td>
                    <td>{{ $t['label'] }}</td>
                    <td>{{ $t['narration'] }}</td>
                    <td class="num">{{ $t['dc'] === 'D' ? Money::format($t['amount']) : '' }}</td>
                    <td class="num">{{ $t['dc'] === 'C' ? Money::format($t['amount']) : '' }}</td>
                    <td class="num">{{ $t['balance_dc'] ? $bal($t['balance_dc'], $t['balance_amount']) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted" style="text-align:center;padding:20px">No transactions{{ $startdate || $enddate ? ' in this period' : ' yet' }}.</td></tr>
            @endforelse
            <tr><td colspan="6"><strong>Closing balance</strong></td><td class="num"><strong>{{ Money::format($closingSigned) }}</strong></td></tr>
        </tbody>
    </table>
</div>
@include('documents._email-box', ['type' => $cfg['side'] === 'ar' ? 'customer-statement' : 'vendor-statement', 'id' => $party->id, 'opts' => array_filter(['startdate' => $startdate ? date('Y-m-d', strtotime($startdate)) : null, 'enddate' => $enddate ? date('Y-m-d', strtotime($enddate)) : null])])
@if ($cfg['side'] === 'ar')@include('sales._reminder-box', ['party' => $party])@endif
@endsection
