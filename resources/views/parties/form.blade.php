@extends('layouts.main')
@section('title', ($party ? 'Edit ' : 'Add ').$cfg['one'])
@section('content')
@php
    $isAr = $cfg['side'] === 'ar';
    $v = fn ($f) => old($f, $party->{$f} ?? '');
@endphp
<div class="bar"><h1 style="margin:0">{{ $party ? 'Edit '.$cfg['one'] : 'Add '.$cfg['one'] }}</h1></div>

<form class="card" method="POST" action="{{ $party ? route($cfg['route'].'.update', $party->id) : route($cfg['route'].'.store') }}">
    @csrf
    @if ($party) @method('PUT') @endif

    <label for="name">{{ $cfg['one'] }} name *</label>
    <input id="name" name="name" value="{{ $v('name') }}" required maxlength="255" autofocus>
    @error('name')<p class="field-error">{{ $message }}</p>@enderror

    <div class="row2">
        <div><label for="contact_person">Contact person</label><input id="contact_person" name="contact_person" value="{{ $v('contact_person') }}" maxlength="255"></div>
        <div><label for="phone">Phone</label><input id="phone" name="phone" value="{{ $v('phone') }}" maxlength="50"></div>
    </div>

    <label for="email">Email</label>
    <input id="email" type="email" name="email" value="{{ $v('email') }}" maxlength="255">
    @error('email')<p class="field-error">{{ $message }}</p>@enderror

    <label for="address">Address</label>
    <textarea id="address" name="address" rows="2" maxlength="500">{{ $v('address') }}</textarea>

    <label for="notes">Notes</label>
    <textarea id="notes" name="notes" rows="2" maxlength="500">{{ $v('notes') }}</textarea>

    @unless ($isAr)
        <label for="expense_ledger_id">Default expense account <span class="muted">(optional)</span></label>
        <select id="expense_ledger_id" name="expense_ledger_id">
            <option value="">Accounts Payable (default)</option>
            @foreach ($expenseLedgers as $l)
                <option value="{{ $l->id }}" @selected((string) old('expense_ledger_id', $party->expense_ledger_id ?? '') === (string) $l->id)>{{ $l->name }}{{ $l->code ? ' ('.$l->code.')' : '' }}</option>
            @endforeach
        </select>
        <p class="muted" style="margin:4px 0 0;font-size:13px">When set, payments to this vendor post straight to this account instead of Accounts Payable.</p>
    @endunless

    @if ($isAr && \App\Support\Money::vehiclesEnabled())
        <h2>Vehicles <span class="muted" style="font-weight:400;font-size:13px">(up to 4)</span></h2>
        @for ($i = 1; $i <= 4; $i++)
            @php $veh = $vehicles[$i - 1] ?? null; @endphp
            <div class="row2" style="grid-template-columns:1fr 1fr 1fr;margin-bottom:8px">
                <input type="hidden" name="vehicle_row_id_{{ $i }}" value="{{ $veh->id ?? '' }}">
                <input name="vehicle_model_{{ $i }}" placeholder="Vehicle model" value="{{ old("vehicle_model_$i", $veh->model ?? '') }}" maxlength="255">
                <input name="vehicle_plate_{{ $i }}" placeholder="Plate #" value="{{ old("vehicle_plate_$i", $veh->plate_number ?? '') }}" maxlength="50">
                <select name="vehicle_pricing_model_{{ $i }}">
                    <option value="">Pricing model (optional)</option>
                    @foreach ($vehicleModels as $id => $name)
                        <option value="{{ $id }}" @selected((string) old("vehicle_pricing_model_$i", $veh->vehicle_model_id ?? '') === (string) $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endfor
    @endif

    @if ($party)
        <label style="margin-top:16px"><input type="checkbox" name="status" value="1" @checked(old('status', $party->status))> Active</label>
    @endif

    <div class="actions" style="margin-top:20px">
        <button type="submit">{{ $party ? 'Save changes' : 'Create '.strtolower($cfg['one']) }}</button>
        @unless ($party)<button type="submit" name="save_and_new" value="1" class="ghost">Save &amp; add another</button>@endunless
        <a class="btn ghost" href="{{ route($cfg['route'].'.index') }}">Cancel</a>
    </div>
</form>
@endsection
