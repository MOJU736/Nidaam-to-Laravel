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
<div class="bar">
    <div>
        <h1 style="margin:0">{{ $party->name }} @unless ($party->status)<span class="pill">Inactive</span>@endunless</h1>
        <a href="{{ route($cfg['route'].'.index') }}" class="muted">&larr; {{ $cfg['many'] }}</a>
    </div>
    <div class="actions">
        <a class="btn ghost" href="{{ route($cfg['route'].'.edit', $party->id) }}">Edit</a>
        @if ($cfg['side'] === 'ar')@if ($closing['dc'] === 'D' && Money::positive($closing['amount']))<button type="button" class="ghost" onclick="var e=document.getElementById('reminderBox');if(e){e.hidden=!e.hidden;if(!e.hidden)e.scrollIntoView({behavior:'smooth',block:'center'})}">Send reminder</button>@endif<a class="btn ghost" href="{{ route('quotations.create', $party->id) }}">New quotation</a><a class="btn ghost" href="{{ route('invoices.create', $party->id) }}">New invoice</a><a class="btn" href="{{ route('payments.create', $party->id) }}">Receive payment</a>@else<a class="btn ghost" href="{{ route('purchase-orders.create', $party->id) }}">New purchase order</a><a class="btn ghost" href="{{ route('bills.create', $party->id) }}">New bill</a><a class="btn" href="{{ route('vendor-payments.create', $party->id) }}">Pay vendor</a>@endif
    </div>
</div>

<div class="card">
    <dl class="kv">
        <dt>Contact</dt><dd>{{ $party->contact_person ?: '—' }}</dd>
        <dt>Phone</dt><dd>{{ $party->phone ?: '—' }}</dd>
        <dt>Email</dt><dd>{{ $party->email ?: '—' }}</dd>
        <dt>Address</dt><dd>{!! nl2br(e($party->address ?: '—')) !!}</dd>
        @if ($party->notes)<dt>Notes</dt><dd>{!! nl2br(e($party->notes)) !!}</dd>@endif
        <dt>{{ $cfg['side'] === 'ar' ? 'Owes you' : 'You owe' }}</dt><dd><strong>{{ Money::format($closingSigned) }}</strong></dd>
    </dl>
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
