@extends('layouts.main')
@section('title', 'Purchase order '.$purchases->poLabel($po->number))
@section('content')
@php use App\Support\Money; $doc = $po; @endphp
@include('purchases._tabs')
<a class="back" href="{{ route('purchase-orders.index') }}">&larr; Purchase orders</a>
<div class="bar">
    <div class="titlerow"><h1>Purchase order {{ $purchases->poLabel($po->number) }}</h1><span class="pill {{ $po->status === 'converted' ? 'owes' : '' }}">{{ ucfirst($po->status) }}</span></div>
    <div class="actions">
        @if ($po->status !== 'converted')
            <form class="inline" method="POST" action="{{ route('purchase-orders.convert', $po->id) }}" onsubmit="return confirm('Convert this purchase order to a draft bill?')">@csrf<button>Convert to bill</button></form>
        @endif
        @include('documents._actions', ['type' => 'purchase-order', 'id' => $po->id])
        @if ($po->status !== 'converted')
        <details class="menu">
            <summary>More <svg class="ic" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></summary>
            <div class="drop"><a href="{{ route('purchase-orders.edit', $po->id) }}">Edit</a><hr>
                <form method="POST" action="{{ route('purchase-orders.destroy', $po->id) }}" onsubmit="return confirm('Delete this purchase order?')">@csrf @method('DELETE')<button class="danger">Delete order</button></form></div>
        </details>
        @endif
    </div>
</div>
<div class="docgrid">
    <div class="card docmeta">
        <div class="wide2"><small>Ordered from</small><div class="who"><a href="{{ route('vendors.show', $po->vendor_id) }}">{{ $po->vendor_name }}</a></div></div>
        <div><small>Date</small>{{ Money::date($po->date) }}</div>
        @if ($po->due_date)<div><small>Due</small>{{ Money::date($po->due_date) }}</div>@endif
        @if ($po->notes)<div class="wide2"><small>Notes</small>{!! nl2br(e($po->notes)) !!}</div>@endif
    </div>
    <div class="card balance {{ $po->status === 'converted' ? 'settled' : '' }}">
        <small>{{ $po->status === 'converted' ? 'Became a bill' : 'Order total' }}</small>
        <div class="big">{{ Money::format($po->total) }}</div>
    </div>
</div>
@include('purchases._doc-lines')
@include('documents._email-box', ['type' => 'purchase-order', 'id' => $po->id])
@endsection
