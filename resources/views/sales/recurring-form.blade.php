@extends('layouts.main')
@php
    $isEdit = (bool) $tpl->id;
    $taxPct = ($tpl->tax_percent !== null && $tpl->tax_percent !== '') ? rtrim(rtrim((string) $tpl->tax_percent, '0'), '.') : '';
    $oldLines = old('lines');
    $lines = is_array($oldLines) ? array_values($oldLines) : $tpl->items->map(fn ($l) => ['item_id' => $l->item_id, 'description' => $l->description, 'qty' => rtrim(rtrim((string) $l->qty, '0'), '.'), 'unit_price' => $l->unit_price,
        'discount_type' => $l->discount_type, 'discount_value' => $l->discount_value])->all();
@endphp
@section('title', $isEdit ? 'Edit recurring invoice' : 'Make recurring')
@section('content')
@include('sales._tabs')
<div class="bar"><h1 style="margin:0">{{ $isEdit ? 'Edit recurring invoice' : 'Make recurring' }}</h1><a class="muted" href="{{ route('recurring.index') }}">&larr; Recurring</a></div>
<form class="card" method="POST" action="{{ $isEdit ? route('recurring.update', $tpl->id) : route('recurring.store') }}">
    @csrf
    @if ($isEdit) @method('PUT') @endif
    @foreach (['name', 'every', 'start_date', 'end_date', 'due_days'] as $k)@if ($errors->has($k))<p class="error">{{ $errors->first($k) }}</p>@endif @endforeach
    @if ($errors->has('recurring'))<p class="error">{{ $errors->first('recurring') }}</p>@endif
    <input type="hidden" name="customer_id" value="{{ $tpl->customer_id }}">
    <input type="hidden" name="doc_discount_type" value="{{ $tpl->discount_type }}"><input type="hidden" name="doc_discount_value" value="{{ $tpl->discount_value }}">
    <input type="hidden" name="tax_percent" value="{{ $taxPct }}"><input type="hidden" name="tax_total" value="{{ $tpl->tax_total }}">
    <p class="muted" style="margin-top:0">On each date, a new invoice for <strong>{{ $tpl->customer_name }}</strong> is created as a <strong>draft</strong> with these lines. You check it and click Issue; nothing is posted or emailed on its own.@if ($taxPct !== '') Tax of {{ $taxPct }}% is added.@endif @if ($tpl->discount_type) The document discount is kept.@endif</p>

    <div class="row2">
        <div><label for="name" style="margin-top:0">Name <span class="muted">(for your list, e.g. "Office rent")</span></label><input id="name" name="name" maxlength="150" required value="{{ old('name', $tpl->name) }}"></div>
        <div><label style="margin-top:0">Customer</label><input value="{{ $tpl->customer_name }}" readonly></div>
    </div>
    <div class="row2" style="grid-template-columns:90px 160px 1fr 110px 1fr;margin-top:8px">
        <div><label for="every">Every</label><input id="every" type="number" name="every" min="1" max="99" required value="{{ old('every', $tpl->every) }}"></div>
        <div><label for="frequency">&nbsp;</label><select id="frequency" name="frequency">@foreach ($frequencies as $k => $v)<option value="{{ $k }}" @selected(old('frequency', $tpl->frequency) === $k)>{{ $v }}</option>@endforeach</select></div>
        <div><label for="start_date">{{ $isEdit ? 'Next invoice on' : 'First invoice on' }}</label><input id="start_date" type="date" name="start_date" required value="{{ old('start_date', substr((string) $tpl->start_date, 0, 10)) }}"></div>
        <div><label for="due_days">Due in (days)</label><input id="due_days" type="number" name="due_days" min="0" max="365" placeholder="none" value="{{ old('due_days', $tpl->due_days) }}"></div>
        <div><label for="end_date">Ends (optional)</label><input id="end_date" type="date" name="end_date" value="{{ old('end_date', $tpl->end_date ? substr((string) $tpl->end_date, 0, 10) : '') }}"></div>
    </div>
    @if ($isEdit)
        <label for="status">Status</label><select id="status" name="status" style="max-width:200px"><option value="active" @selected($tpl->status !== 'paused')>Active</option><option value="paused" @selected($tpl->status === 'paused')>Paused</option></select>
    @endif

    <h2>Lines on each invoice</h2>
    <p class="muted" style="margin-top:0">Change a price or quantity here and every future invoice uses it. Tick Remove to drop a line.</p>
    <div class="tbl"><table>
        <thead><tr><th>Description</th><th class="num" style="width:110px">Qty</th><th class="num" style="width:140px">Price</th><th style="width:80px">Remove</th></tr></thead>
        <tbody>
        @foreach ($lines as $i => $l)
            <tr>
                <td><input type="hidden" name="lines[{{ $i }}][item_id]" value="{{ $l['item_id'] }}"><input type="hidden" name="lines[{{ $i }}][discount_type]" value="{{ $l['discount_type'] }}"><input type="hidden" name="lines[{{ $i }}][discount_value]" value="{{ $l['discount_value'] }}">
                    <input name="lines[{{ $i }}][description]" maxlength="255" value="{{ $l['description'] }}"></td>
                <td><input class="num" name="lines[{{ $i }}][qty]" inputmode="decimal" value="{{ $l['qty'] }}"></td>
                <td><input class="num" name="lines[{{ $i }}][unit_price]" inputmode="decimal" value="{{ $l['unit_price'] }}"></td>
                <td style="text-align:center"><input type="checkbox" name="lines[{{ $i }}][remove]" value="1" aria-label="Remove"></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" maxlength="500">{{ old('notes', $tpl->notes) }}</textarea>
    <div class="actions" style="margin-top:20px"><button>{{ $isEdit ? 'Save changes' : 'Start recurring' }}</button><a class="btn ghost" href="{{ route('recurring.index') }}">Cancel</a></div>
</form>
@endsection
