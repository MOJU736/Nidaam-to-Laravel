@extends('layouts.main')
@section('title', 'Edit invoice details')
@section('content')
@php use App\Support\Money; @endphp
@include('sales._tabs')
<a class="back" href="{{ route('invoices.show', $inv->id) }}">&larr; Invoice</a>
<div class="bar"><h1>Invoice {{ $sales->label('invoice', $inv->number) }} - due date and notes</h1></div>
<form class="card" method="POST" action="{{ route('invoices.update', $inv->id) }}" style="max-width:560px">
    @csrf @method('PUT')
    <p class="muted" style="margin-top:0">This invoice is issued, so its lines and amounts are locked - they are what was posted to the books. To change them, revert it to draft (or void it and make a new one).</p>
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <label for="due_date">Due date</label><input id="due_date" type="date" name="due_date" value="{{ old('due_date', $inv->due_date ? substr($inv->due_date, 0, 10) : '') }}">
    <label for="notes">Notes</label><textarea id="notes" name="notes" rows="3" maxlength="500">{{ old('notes', $inv->notes) }}</textarea>
    <div class="actions" style="margin-top:20px"><button>Save</button><a class="btn ghost" href="{{ route('invoices.show', $inv->id) }}">Cancel</a></div>
</form>
@endsection
