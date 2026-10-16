@extends('layouts.main')
@section('title', 'Item settings')
@section('content')
@include('items._tabs')
<div class="bar"><h1>Item settings</h1></div>
<form class="card" method="POST" action="{{ route('items.settings') }}" style="max-width:640px">
    @csrf @method('PUT')
    <p class="muted" style="margin-top:0">The accounts stock is posted to. Set once.</p>
    @foreach ([['inventory_asset_ledger_id', 'Inventory Asset account', 'The value of stock on hand.'], ['cogs_ledger_id', 'Cost of Goods Sold account', 'The cost of stock sold on invoices.']] as [$f, $label, $hint])
        <label for="{{ $f }}">{{ $label }}</label>
        <select id="{{ $f }}" name="{{ $f }}"><option value="">Not set</option>@foreach ($ledgers as $id => $n)<option value="{{ $id }}" @selected((string) old($f, $settings->{$f} ?? '') === (string) $id)>{{ $n }}</option>@endforeach</select>
        <p class="muted" style="margin:4px 0 0;font-size:13px">{{ $hint }}</p>
    @endforeach
    @if ($hasAdjustment)
        <label for="adjustment_ledger_id">Stock adjustments account</label>
        <select id="adjustment_ledger_id" name="adjustment_ledger_id"><option value="">Automatic ("Inventory Adjustments")</option>@foreach ($ledgers as $id => $n)<option value="{{ $id }}" @selected((string) old('adjustment_ledger_id', $settings->adjustment_ledger_id ?? '') === (string) $id)>{{ $n }}</option>@endforeach</select>
        <p class="muted" style="margin:4px 0 0;font-size:13px">Where the value of stock written off, or found in a count, is posted.</p>
    @endif
    <div class="actions" style="margin-top:20px"><button>Save settings</button></div>
</form>
@endsection
