@extends('layouts.main')
@section('title', 'Purchase order '.$purchases->poLabel($po->number))
@section('content')
@php use App\Support\Money; $doc = $po; @endphp
@include('purchases._tabs')
<div class="bar">
    <div><h1 style="margin:0">Purchase order {{ $purchases->poLabel($po->number) }} <span class="pill {{ $po->status === 'converted' ? 'owes' : '' }}">{{ ucfirst($po->status) }}</span></h1><a class="muted" href="{{ route('purchase-orders.index') }}">&larr; Purchase orders</a></div>
    <div class="actions">
        @include('documents._actions', ['type' => 'purchase-order', 'id' => $po->id])
        @if ($po->status !== 'converted')
            <form class="inline" method="POST" action="{{ route('purchase-orders.convert', $po->id) }}" onsubmit="return confirm('Convert this purchase order to a draft bill?')">@csrf<button>Convert to bill</button></form>
            <a class="btn ghost" href="{{ route('purchase-orders.edit', $po->id) }}">Edit</a>
            <form class="inline" method="POST" action="{{ route('purchase-orders.destroy', $po->id) }}" onsubmit="return confirm('Delete this purchase order?')">@csrf @method('DELETE')<button class="danger">Delete</button></form>
        @endif
    </div>
</div>
<div class="card" style="margin-bottom:16px"><dl class="kv"><dt>Vendor</dt><dd><a href="{{ route('vendors.show', $po->vendor_id) }}">{{ $po->vendor_name }}</a></dd><dt>Date</dt><dd>{{ Money::date($po->date) }}</dd>
    @if ($po->due_date)<dt>Due</dt><dd>{{ Money::date($po->due_date) }}</dd>@endif @if ($po->notes)<dt>Notes</dt><dd>{!! nl2br(e($po->notes)) !!}</dd>@endif</dl></div>
@include('purchases._doc-lines')
@include('documents._email-box', ['type' => 'purchase-order', 'id' => $po->id])
@endsection
