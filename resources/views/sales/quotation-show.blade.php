@extends('layouts.main')
@section('title', 'Quotation '.$sales->label('quotation', $q->number))
@section('content')
@php use App\Support\Money; $doc = $q; @endphp
@include('sales._tabs')
<div class="bar">
    <div><h1 style="margin:0">Quotation {{ $sales->label('quotation', $q->number) }} <span class="pill {{ $q->status === 'converted' ? 'owes' : '' }}">{{ ucfirst($q->status) }}</span></h1><a class="muted" href="{{ route('quotations.index') }}">&larr; Quotations</a></div>
    <div class="actions">
        @include('documents._actions', ['type' => 'quotation', 'id' => $q->id])
        @if ($q->status !== 'converted')
            <form class="inline" method="POST" action="{{ route('quotations.convert', $q->id) }}" onsubmit="return confirm('Convert this quotation to a draft invoice?')">@csrf<button>Convert to invoice</button></form>
            <a class="btn ghost" href="{{ route('quotations.edit', $q->id) }}">Edit</a>
        @endif
        <form class="inline" method="POST" action="{{ route('quotations.destroy', $q->id) }}" onsubmit="return confirm('Delete this quotation?')">@csrf @method('DELETE')<button class="danger">Delete</button></form>
    </div>
</div>
<div class="card" style="margin-bottom:16px"><dl class="kv">
    <dt>Customer</dt><dd><a href="{{ route('customers.show', $q->customer_id) }}">{{ $q->customer_name }}</a></dd>
    <dt>Date</dt><dd>{{ Money::date($q->date) }}</dd>@if ($q->expiry_date)<dt>Valid until</dt><dd>{{ Money::date($q->expiry_date) }}</dd>@endif
    @if ($vehicles)<dt>Vehicles</dt><dd>{{ implode(', ', $vehicles) }}</dd>@endif
    @if ($q->notes)<dt>Notes</dt><dd>{!! nl2br(e($q->notes)) !!}</dd>@endif
</dl></div>
@include('sales._doc-lines')
@include('documents._email-box', ['type' => 'quotation', 'id' => $q->id])
@endsection
