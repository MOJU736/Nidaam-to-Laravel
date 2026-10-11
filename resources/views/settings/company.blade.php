@extends('layouts.main')
@section('title', 'Company settings')
@section('content')
@include('settings._tabs')
<div class="bar"><h1 style="margin:0">Company settings</h1></div>
@php
    $logoFile = $s->logo && is_file(public_path('branding/logo/'.$s->logo)) ? asset('branding/logo/'.$s->logo) : null;
    $cur = old('currency_symbol', $s->currency_symbol);
    $curs = ['$' => 'US Dollar ($)', 'USD' => 'US Dollar (USD)', 'R' => 'South African Rand (R)', 'KES' => 'Kenyan Shilling (KES)', 'ETB' => 'Ethiopian Birr (ETB)', 'SOS' => 'Somali Shilling (SOS)', '€' => 'Euro (€)', '£' => 'Pound (£)', 'AED' => 'UAE Dirham (AED)'];
    if ($cur !== '' && ! isset($curs[$cur])) { $curs[$cur] = $cur; }
    $fy = old('fy_start', substr((string) $s->getRawOriginal('fy_start'), 0, 10));
@endphp
@if ($locked)<div class="flash bad">This company is locked, so these settings cannot be changed. <a href="{{ route('settings.lock') }}">Unlock it</a> first.</div>@endif
<form class="card" method="POST" action="{{ route('settings.company') }}" style="max-width:760px" autocomplete="off">
    @csrf @method('PUT')
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <h2 style="margin-top:0">Company</h2>
    <div style="display:flex;gap:14px;align-items:center;margin-bottom:6px">
        @if ($logoFile)<img src="{{ $logoFile }}" alt="Company logo" style="max-height:56px;max-width:160px">@else<span class="muted">No logo yet.</span>@endif
        @can('page', 'settings.templates')<a href="{{ route('settings.templates') }}">{{ $logoFile ? 'Change the logo' : 'Add a logo' }} (under Templates &amp; logo)</a>@endcan
    </div>
    <div class="row2">
        <div><label for="name">Company name</label><input id="name" name="name" maxlength="255" required value="{{ old('name', $s->name) }}"></div>
        <div><label for="email">Email</label><input id="email" type="email" name="email" maxlength="255" required value="{{ old('email', $s->email) }}"></div>
    </div>
    <label for="address">Address <span class="muted">(optional)</span></label><input id="address" name="address" maxlength="255" value="{{ old('address', $s->address) }}">

    <h2>Money and dates</h2>
    <div class="row2">
        <div><label for="currency_symbol">Currency symbol</label><input id="currency_symbol" name="currency_symbol" maxlength="10" required list="curs" value="{{ $cur }}"><datalist id="curs">@foreach ($curs as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</datalist></div>
        <div><label for="decimal_places">Decimal places</label><select id="decimal_places" name="decimal_places">@foreach ([2, 1, 0] as $n)<option value="{{ $n }}" @selected((int) old('decimal_places', $s->decimal_places) === $n)>{{ $n }}</option>@endforeach</select></div>
    </div>
    <div class="row2">
        <div><label for="currency_format">Number format</label><select id="currency_format" name="currency_format">@foreach ($numberFormats as $k => $l)<option value="{{ $k }}" @selected(old('currency_format', $s->currency_format) === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label for="date_format">Date format</label><select id="date_format" name="date_format">@foreach ($dateFormats as $k => $l)<option value="{{ $k }}" @selected(old('date_format', $s->date_format) === $k)>{{ now()->format(explode('|', $k)[0]) }} ({{ $l }})</option>@endforeach</select></div>
    </div>

    <h2>Financial year</h2>
    <div class="row2">
        <div><label for="fy_start">The financial year starts on</label><input id="fy_start" type="date" name="fy_start" required value="{{ $fy }}"></div>
        <div><label>and ends on</label><input value="{{ \App\Support\Money::date($end) }}" disabled></div>
    </div>
    <p class="muted" style="font-size:13px;margin:4px 0 0">A financial year is always twelve months, so only its first day is asked for.</p>

    <h2>Features</h2>
    @foreach ([['vehicles_enabled', 'Vehicles', 'A Vehicles section on customers, quotations and invoices (for an auto service business).'], ['commissions_enabled', 'Commissions', 'Record and settle money received on a client\'s behalf.'], ['billable_expenses_enabled', 'Billable expenses', 'Mark money out as billable to a customer.']] as [$f, $label, $hint])
        <label style="font-weight:400"><input type="checkbox" name="{{ $f }}" value="1" @checked(old($f, $s->{$f} ?? false))> <strong>{{ $label }}</strong> <span class="muted">{{ $hint }}</span></label>
    @endforeach
    <div class="actions" style="margin-top:20px"><button @disabled($locked)>Save settings</button></div>
</form>
@endsection
