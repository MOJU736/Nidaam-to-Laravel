@extends('layouts.main')
@section('title', 'Write off '.$label)
@section('content')
@php use App\Support\Money; @endphp
@include('sales._tabs')
<div class="bar"><h1 style="margin:0">Write off {{ $label }}</h1><a class="muted" href="{{ route('invoices.show', $inv->id) }}">&larr; Invoice</a></div>
<form class="card" method="POST" action="{{ route('invoices.write-off', $inv->id) }}" style="max-width:640px" onsubmit="return confirm('Write this off as a bad debt? It posts to the books.')">
    @csrf
    @foreach (['amount', 'date', 'reason'] as $k)@if ($errors->has($k))<p class="error">{{ $errors->first($k) }}</p>@endif @endforeach
    <p style="margin-top:0">{{ $inv->customer_name }} still owes <strong>{{ Money::format($due) }}</strong> on this invoice. Writing off what they will never pay charges it to <strong>Bad Debts</strong> and marks the invoice paid by it. Tax already charged on the invoice is not reversed. If the customer pays after all, open the write-off and void it.</p>
    <div class="row2">
        <div><label for="amount" style="margin-top:0">Amount to write off *</label><input id="amount" name="amount" inputmode="decimal" required value="{{ old('amount', $due) }}"></div>
        <div><label for="date" style="margin-top:0">Date *</label><input id="date" type="date" name="date" required value="{{ old('date', now()->format('Y-m-d')) }}"></div>
    </div>
    <label for="reason">Reason</label><input id="reason" name="reason" maxlength="500" value="{{ old('reason') }}">
    <div class="actions" style="margin-top:20px"><button>Write off</button><a class="btn ghost" href="{{ route('invoices.show', $inv->id) }}">Cancel</a></div>
</form>
@endsection
