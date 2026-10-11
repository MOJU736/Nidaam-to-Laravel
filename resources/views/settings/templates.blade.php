@extends('layouts.main')
@section('title', 'Document templates')
@section('content')
@include('settings._tabs')
@php
    $v = fn ($k, $default = '') => old("look.$k", $saved[$k] ?? $default);
    $co = fn ($k) => old($k, $s->{$k} ?? '');
@endphp
<div class="bar"><div><h1 style="margin:0">Document templates</h1><div class="muted">How invoices, quotations, credit notes, receipts and statements look when printed or emailed.</div></div></div>

<h2>1. Pick a design</h2>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:6px">
@foreach ($designs as $id => $dd)
    <a href="{{ route('settings.templates', ['design' => $id]) }}" class="card" style="text-decoration:none;color:inherit;padding:10px;border:2px solid {{ $design === $id ? $dd['main'] : 'transparent' }}">
        <div style="height:46px;border-radius:4px;background:{{ $dd['main'] }};display:flex;align-items:flex-end"><div style="height:10px;width:100%;background:{{ $dd['second'] }}"></div></div>
        <div style="margin-top:6px"><strong>{{ $id }}. {{ $dd['name'] }}</strong>@if ($chosen === $id)<div class="muted" style="font-size:12px">In use</div>@endif</div>
    </a>
@endforeach
</div>

<form class="card" method="POST" action="{{ route('settings.templates') }}" enctype="multipart/form-data" id="tplForm" style="margin-top:14px">
    @csrf
    <input type="hidden" name="design" value="{{ $design }}">
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <h2 style="margin-top:0">2. Make design {{ $design }} yours <span class="muted" style="font-size:13px;font-weight:400">({{ $designs[$design]['name'] }}; each design keeps its own look)</span></h2>

    <div class="row2" style="grid-template-columns:repeat(4,1fr)">
        @foreach (['c_main' => 'Main colour', 'c_second' => 'Second colour', 'c_head' => 'Table heading', 'c_row' => 'Row shading'] as $k => $label)
            <div><label for="look_{{ $k }}">{{ $label }}</label><input id="look_{{ $k }}" type="color" name="look[{{ $k }}]" value="{{ $v($k, $defaults[$k]) }}" style="height:38px;padding:2px"></div>
        @endforeach
    </div>
    <div class="row2" style="grid-template-columns:repeat(4,1fr);margin-top:8px">
        <div><label for="look_font">Font</label><select id="look_font" name="look[font]">@foreach ($fonts as $k => [$label])<option value="{{ $k }}" @selected($v('font', 'sans') === $k)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="look_size">Text size</label><select id="look_size" name="look[size]"><option value="">Normal</option><option value="small" @selected($v('size') === 'small')>Small</option><option value="large" @selected($v('size') === 'large')>Large</option></select></div>
        <div><label for="look_rows">Empty rows to fill</label><input id="look_rows" type="number" min="0" max="8" name="look[rows]" value="{{ $v('rows') }}" placeholder="0"></div>
        <div><label for="look_foot_align">Footer alignment</label><select id="look_foot_align" name="look[foot_align]">@foreach (['center' => 'Centre', 'left' => 'Left', 'right' => 'Right'] as $k => $label)<option value="{{ $k }}" @selected($v('foot_align', 'center') === $k)>{{ $label }}</option>@endforeach</select></div>
    </div>

    <h3>Logo, signature and stamp</h3>
    <div class="row2" style="grid-template-columns:repeat(3,1fr)">
        <div><label for="logo_file">Company logo @if ($company->logo)<span class="muted">(now: {{ $company->logo }})</span>@endif</label><input id="logo_file" type="file" name="logo_file" accept="image/png,image/jpeg,image/gif">
            @if ($company->logo)<label style="font-weight:400"><input type="checkbox" name="remove_logo" value="1"> Remove the logo</label>@endif</div>
        <div><label for="signature_file">Signature image @if ($s->signature_image)<span class="muted">(saved)</span>@endif</label><input id="signature_file" type="file" name="signature_file" accept="image/png,image/jpeg,image/gif">
            @if ($s->signature_image)<label style="font-weight:400"><input type="checkbox" name="remove_signature" value="1"> Remove it</label>@endif</div>
        <div><label for="stamp_file">Stamp image @if ($s->stamp_image)<span class="muted">(saved)</span>@endif</label><input id="stamp_file" type="file" name="stamp_file" accept="image/png,image/jpeg,image/gif">
            @if ($s->stamp_image)<label style="font-weight:400"><input type="checkbox" name="remove_stamp" value="1"> Remove it</label>@endif</div>
    </div>
    <div class="row2" style="grid-template-columns:repeat(4,1fr);margin-top:8px">
        <div><label for="look_logo_size">Logo size</label><select id="look_logo_size" name="look[logo_size]">@foreach (['s' => 'Small', 'm' => 'Medium', 'l' => 'Large'] as $k => $label)<option value="{{ $k }}" @selected($v('logo_size', 'm') === $k)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="look_logo_pos">Logo position</label><select id="look_logo_pos" name="look[logo_pos]">@foreach (['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'] as $k => $label)<option value="{{ $k }}" @selected($v('logo_pos', 'left') === $k)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="look_sig_size">Signature size %</label><input id="look_sig_size" type="number" min="40" max="220" name="look[sig_size]" value="{{ $v('sig_size', 100) }}"></div>
        <div><label for="look_stamp_size">Stamp size %</label><input id="look_stamp_size" type="number" min="40" max="220" name="look[stamp_size]" value="{{ $v('stamp_size', 100) }}"></div>
    </div>
    <div class="row2" style="grid-template-columns:repeat(4,1fr);margin-top:8px">
        @foreach (['sig_x' => 'Signature across (px)', 'sig_y' => 'Signature down (px)', 'stamp_x' => 'Stamp across (px)', 'stamp_y' => 'Stamp down (px)'] as $k => $label)
            <div><label for="look_{{ $k }}">{{ $label }}</label><input id="look_{{ $k }}" type="number" name="look[{{ $k }}]" value="{{ $v($k, 0) }}"></div>
        @endforeach
    </div>

    <h3>Words on the page</h3>
    <div class="row2" style="grid-template-columns:repeat(3,1fr)">
        @foreach ($words as $k => [$label, $usual])
            <div><label for="look_{{ $k }}">{{ $label }}</label><input id="look_{{ $k }}" name="look[{{ $k }}]" maxlength="40" placeholder="{{ $usual }}" value="{{ $v($k) }}"></div>
        @endforeach
    </div>

    <h3>Parts to show</h3>
    <div class="actions" style="gap:18px">
        @foreach ($parts as $k => $label)
            <label style="font-weight:400;margin:0"><input type="checkbox" name="look[hide][]" value="{{ $k }}" @checked(in_array($k, (array) old('look.hide', $saved['hide'] ?? []), true))> Hide {{ strtolower($label) }}</label>
        @endforeach
    </div>

    <h2>3. Details printed on every document <span class="muted" style="font-size:13px;font-weight:400">(the same for all designs)</span></h2>
    <div class="row2"><div><label for="signature_name">Signature name</label><input id="signature_name" name="signature_name" maxlength="150" value="{{ $co('signature_name') }}"></div>
        <div><label for="signature_title">Signature title</label><input id="signature_title" name="signature_title" maxlength="150" value="{{ $co('signature_title') }}"></div></div>
    <div class="row2" style="margin-top:8px"><div><label for="footer_address">Footer address <span class="muted">(blank = the company address)</span></label><input id="footer_address" name="footer_address" maxlength="255" value="{{ $co('footer_address') }}"></div>
        <div><label for="footer_phone">Footer phone</label><input id="footer_phone" name="footer_phone" maxlength="100" value="{{ $co('footer_phone') }}"></div></div>
    <div class="row2" style="margin-top:8px"><div><label for="footer_email">Footer email <span class="muted">(blank = the company email)</span></label><input id="footer_email" name="footer_email" maxlength="150" value="{{ $co('footer_email') }}"></div>
        <div><label for="footer_website">Footer website</label><input id="footer_website" name="footer_website" maxlength="150" value="{{ $co('footer_website') }}"></div></div>
    <div class="row2" style="margin-top:8px"><div><label for="qty_label">Quantity heading (all designs)</label><input id="qty_label" name="qty_label" maxlength="30" placeholder="Qty" value="{{ $co('qty_label') }}"></div>
        <div><label for="price_label">Price heading (all designs)</label><input id="price_label" name="price_label" maxlength="30" placeholder="Price" value="{{ $co('price_label') }}"></div></div>
    <label for="payment_extra">More payment details <span class="muted">(one per line, e.g. mobile money numbers)</span></label><textarea id="payment_extra" name="payment_extra" rows="3" maxlength="1000">{{ $co('payment_extra') }}</textarea>
    <label for="footer_note">Thank-you line at the bottom</label><input id="footer_note" name="footer_note" maxlength="255" placeholder="We appreciate your business and look forward to serving you!" value="{{ $co('footer_note') }}">

    <div class="actions" style="margin-top:22px">
        <button name="then" value="default">Save &amp; use design {{ $design }}</button>
        <button name="then" value="save" class="ghost">Save only</button>
        <button type="submit" formaction="{{ route('settings.templates.preview') }}" formtarget="_blank" formmethod="POST" class="ghost" name="then" value="preview">Preview</button>
        <button name="then" value="reset" class="ghost danger" onclick="return confirm('Put design {{ $design }} back to how it was made?')">Reset this design</button>
    </div>
</form>
@endsection
