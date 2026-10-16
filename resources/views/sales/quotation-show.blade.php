@extends('layouts.main')
@section('title', 'Quotation '.$sales->label('quotation', $q->number))
@section('content')
@php use App\Support\Money; $doc = $q; @endphp
@include('sales._tabs')
<a class="back" href="{{ route('quotations.index') }}">&larr; Quotations</a>
<div class="bar">
    <div class="titlerow"><h1>Quotation {{ $sales->label('quotation', $q->number) }}</h1><span class="pill {{ $q->status === 'converted' ? 'owes' : '' }}">{{ ucfirst($q->status) }}</span></div>
    <div class="actions">
        @if ($q->status !== 'converted')
            <form class="inline" method="POST" action="{{ route('quotations.convert', $q->id) }}" onsubmit="return confirm('Convert this quotation to a draft invoice?')">@csrf<button>Convert to invoice</button></form>
        @endif
        @include('documents._actions', ['type' => 'quotation', 'id' => $q->id])
        <details class="menu">
            <summary>More <svg class="ic" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></summary>
            <div class="drop">
                @if ($q->status !== 'converted')<a href="{{ route('quotations.edit', $q->id) }}">Edit</a><hr>@endif
                <form method="POST" action="{{ route('quotations.destroy', $q->id) }}" onsubmit="return confirm('Delete this quotation?')">@csrf @method('DELETE')<button class="danger">Delete quotation</button></form>
            </div>
        </details>
    </div>
</div>
<div class="docgrid">
    <div class="card docmeta">
        <div class="wide2"><small>Prepared for</small><div class="who"><a href="{{ route('customers.show', $q->customer_id) }}">{{ $q->customer_name }}</a></div></div>
        <div><small>Date</small>{{ Money::date($q->date) }}</div>
        @if ($q->expiry_date)<div><small>Valid until</small>{{ Money::date($q->expiry_date) }}</div>@endif
        @if ($vehicles)<div><small>Vehicles</small>{{ implode(', ', $vehicles) }}</div>@endif
        @if ($q->notes)<div class="wide2"><small>Notes</small>{!! nl2br(e($q->notes)) !!}</div>@endif
    </div>
    <div class="card balance {{ $q->status === 'converted' ? 'settled' : '' }}">
        <small>{{ $q->status === 'converted' ? 'Became an invoice' : 'Quoted total' }}</small>
        <div class="big">{{ Money::format($q->total) }}</div>
        <dl><dt>Subtotal</dt><dd>{{ Money::format($q->subtotal) }}</dd><dt>Tax</dt><dd>{{ Money::format($q->tax_total ?? 0) }}</dd></dl>
    </div>
</div>
@include('sales._doc-lines')
@include('documents._email-box', ['type' => 'quotation', 'id' => $q->id])
@endsection
