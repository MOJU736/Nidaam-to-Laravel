@extends('layouts.main')
@section('title', 'Document templates')
@section('content')
@include('settings._tabs')
@php
    $v = fn ($k, $default = '') => old("look.$k", $saved[$k] ?? $default);
    $co = fn ($k) => old($k, $s->{$k} ?? '');
    $previews = \App\Http\Controllers\DocumentTemplatesController::PREVIEWS;
    $colourNames = ['c_main' => 'Main colour', 'c_second' => 'Accent', 'c_head' => 'Table heading', 'c_row' => 'Row shading'];
@endphp
<div class="bar"><div><h1>Document templates</h1><div class="muted">How your invoices, quotations, receipts, statements and item statements look when printed or emailed. The page on the right updates as you type.</div></div></div>

<div class="tpl">
<form class="tplform" method="POST" action="{{ route('settings.templates') }}" enctype="multipart/form-data" id="tplForm">
    @csrf
    <input type="hidden" name="design" value="{{ $design }}">
    @foreach ($errors->all() as $e)<div class="flash bad">{{ $e }}</div>@endforeach

    <details class="sec" open>
        <summary><span class="n">1</span> Design <small>{{ $designs[$design]['name'] }} @if ($chosen === $design)· in use @endif</small></summary>
        <div class="designs">
            @foreach ($designs as $id => $dd)
                <a href="{{ route('settings.templates', ['design' => $id]) }}" class="dz {{ $design === $id ? 'on' : '' }}" title="{{ $dd['name'] }}">
                    <span class="mini mini-{{ $dd['layout'] }}" style="--m: {{ $dd['main'] }}; --s: {{ $dd['second'] }}; --r: {{ $dd['row'] }}"><i class="a"></i><i class="b"></i><i class="c"></i><i class="d"></i></span>
                    <b>{{ $dd['name'] }}</b>
                    @if ($chosen === $id)<em>In use</em>@endif
                </a>
            @endforeach
        </div>
        <p class="muted" style="margin:10px 0 0;font-size:13px">Each design keeps its own colours and words. Changing design reloads this page, so save first if you have unsaved changes.</p>
    </details>

    <details class="sec" open>
        <summary><span class="n">2</span> Colours and text</summary>
        <div class="swatches">
            @foreach ($colourNames as $k => $label)
                <div><label for="look_{{ $k }}">{{ $label }}</label><input id="look_{{ $k }}" type="color" name="look[{{ $k }}]" value="{{ $v($k, $defaults[$k]) }}"></div>
            @endforeach
        </div>
        <div class="fgrid" style="margin-top:6px">
            <div><label for="look_font">Font</label><select id="look_font" name="look[font]">@foreach ($fonts as $k => [$label])<option value="{{ $k }}" @selected($v('font', $designs[$design]['font'] ?? 'sans') === $k)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="look_size">Text size</label><select id="look_size" name="look[size]"><option value="">Normal</option><option value="small" @selected($v('size') === 'small')>Small</option><option value="large" @selected($v('size') === 'large')>Large</option></select></div>
            <div><label for="look_rows">Empty rows to fill</label><input id="look_rows" type="number" min="0" max="8" name="look[rows]" value="{{ $v('rows') }}" placeholder="0"></div>
        </div>
    </details>

    <details class="sec">
        <summary><span class="n">3</span> Logo, signature and stamp</summary>
        <div class="fgrid">
            <div><label for="logo_file">Company logo @if ($company->logo)<span class="muted">(saved)</span>@endif</label><input id="logo_file" type="file" name="logo_file" accept="image/png,image/jpeg,image/gif">
                @if ($company->logo)<label class="chk"><input type="checkbox" name="remove_logo" value="1"> Remove the logo</label>@endif</div>
            <div><label for="signature_file">Signature image @if ($s->signature_image)<span class="muted">(saved)</span>@endif</label><input id="signature_file" type="file" name="signature_file" accept="image/png,image/jpeg,image/gif">
                @if ($s->signature_image)<label class="chk"><input type="checkbox" name="remove_signature" value="1"> Remove it</label>@endif</div>
            <div><label for="stamp_file">Stamp image @if ($s->stamp_image)<span class="muted">(saved)</span>@endif</label><input id="stamp_file" type="file" name="stamp_file" accept="image/png,image/jpeg,image/gif">
                @if ($s->stamp_image)<label class="chk"><input type="checkbox" name="remove_stamp" value="1"> Remove it</label>@endif</div>
        </div>
        <div class="fgrid">
            <div><label for="look_logo_size">Logo size</label><select id="look_logo_size" name="look[logo_size]">@foreach (['s' => 'Small', 'm' => 'Medium', 'l' => 'Large'] as $k => $label)<option value="{{ $k }}" @selected($v('logo_size', 'm') === $k)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="look_logo_pos">Logo position</label><select id="look_logo_pos" name="look[logo_pos]">@foreach (['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'] as $k => $label)<option value="{{ $k }}" @selected($v('logo_pos', 'left') === $k)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="look_foot_align">Footer alignment</label><select id="look_foot_align" name="look[foot_align]">@foreach (['center' => 'Centre', 'left' => 'Left', 'right' => 'Right'] as $k => $label)<option value="{{ $k }}" @selected($v('foot_align', 'center') === $k)>{{ $label }}</option>@endforeach</select></div>
        </div>
        <div class="fgrid four">
            <div><label for="look_sig_size">Signature size %</label><input id="look_sig_size" type="number" min="40" max="220" name="look[sig_size]" value="{{ $v('sig_size', 100) }}"></div>
            <div><label for="look_stamp_size">Stamp size %</label><input id="look_stamp_size" type="number" min="40" max="220" name="look[stamp_size]" value="{{ $v('stamp_size', 100) }}"></div>
            @foreach (['sig_x' => 'Signature across', 'sig_y' => 'Signature down', 'stamp_x' => 'Stamp across', 'stamp_y' => 'Stamp down'] as $k => $label)
                <div><label for="look_{{ $k }}">{{ $label }} (px)</label><input id="look_{{ $k }}" type="number" name="look[{{ $k }}]" value="{{ $v($k, 0) }}"></div>
            @endforeach
        </div>
    </details>

    <details class="sec">
        <summary><span class="n">4</span> Words on the page</summary>
        <div class="fgrid">
            @foreach ($words as $k => [$label, $usual])
                <div><label for="look_{{ $k }}">{{ $label }}</label><input id="look_{{ $k }}" name="look[{{ $k }}]" maxlength="40" placeholder="{{ $usual }}" value="{{ $v($k) }}"></div>
            @endforeach
        </div>
        <div class="fgrid">
            <div><label for="qty_label">Quantity heading <span class="muted">(all designs)</span></label><input id="qty_label" name="qty_label" maxlength="30" placeholder="Qty" value="{{ $co('qty_label') }}"></div>
            <div><label for="price_label">Price heading <span class="muted">(all designs)</span></label><input id="price_label" name="price_label" maxlength="30" placeholder="Price" value="{{ $co('price_label') }}"></div>
        </div>
    </details>

    <details class="sec">
        <summary><span class="n">5</span> Parts to show</summary>
        <div class="toggles">
            @foreach ($parts as $k => $label)
                <label class="chk"><input type="checkbox" name="look[hide][]" value="{{ $k }}" @checked(in_array($k, (array) old('look.hide', $saved['hide'] ?? []), true))> Hide {{ strtolower($label) }}</label>
            @endforeach
        </div>
    </details>

    <details class="sec">
        <summary><span class="n">6</span> Details printed on every document <small>the same for all designs</small></summary>
        <div class="fgrid">
            <div><label for="signature_name">Signature name</label><input id="signature_name" name="signature_name" maxlength="150" value="{{ $co('signature_name') }}"></div>
            <div><label for="signature_title">Signature title</label><input id="signature_title" name="signature_title" maxlength="150" value="{{ $co('signature_title') }}"></div>
            <div><label for="footer_phone">Footer phone</label><input id="footer_phone" name="footer_phone" maxlength="100" value="{{ $co('footer_phone') }}"></div>
            <div><label for="footer_address">Footer address <span class="muted">(blank = company address)</span></label><input id="footer_address" name="footer_address" maxlength="255" value="{{ $co('footer_address') }}"></div>
            <div><label for="footer_email">Footer email <span class="muted">(blank = company email)</span></label><input id="footer_email" name="footer_email" maxlength="150" value="{{ $co('footer_email') }}"></div>
            <div><label for="footer_website">Footer website</label><input id="footer_website" name="footer_website" maxlength="150" value="{{ $co('footer_website') }}"></div>
        </div>
        <label for="payment_extra">More payment details <span class="muted">(one per line, for example mobile money numbers)</span></label><textarea id="payment_extra" name="payment_extra" rows="3" maxlength="1000">{{ $co('payment_extra') }}</textarea>
        <label for="footer_note">Thank-you line at the bottom</label><input id="footer_note" name="footer_note" maxlength="255" placeholder="We appreciate your business and look forward to serving you!" value="{{ $co('footer_note') }}">
    </details>

    <details class="sec">
        <summary><span class="n">7</span> Custom fields <small>extra boxes on quotations and invoices</small></summary>
        <div class="muted" style="margin-bottom:6px;font-size:13.5px">Up to five extra boxes (for example "PO number" or "Vehicle plate"). A box with a name shows on the form; tick where its value also prints.</div>
        <input type="hidden" name="cf_form" value="1">
        @foreach (range(1, 5) as $i)
            @php $docs = array_filter(explode(',', (string) ($s->{"cf{$i}_docs"} ?? ''))); @endphp
            <div class="cfrow">
                <div><label for="cf{{ $i }}_label">Field {{ $i }} name</label><input id="cf{{ $i }}_label" name="cf{{ $i }}_label" maxlength="100" value="{{ old("cf{$i}_label", $s->{"cf{$i}_label"} ?? '') }}"></div>
                <div class="actions" style="flex-wrap:nowrap;padding-bottom:9px">
                    <label class="chk"><input type="checkbox" name="cf{{ $i }}_docs[]" value="invoice" @checked(in_array('invoice', old("cf{$i}_docs", array_values($docs))))> on invoices</label>
                    <label class="chk"><input type="checkbox" name="cf{{ $i }}_docs[]" value="quotation" @checked(in_array('quotation', old("cf{$i}_docs", array_values($docs))))> on quotations</label>
                </div>
            </div>
        @endforeach
    </details>

    <div class="savebar">
        <button name="then" value="default">Save &amp; use this design</button>
        <button name="then" value="save" class="ghost">Save only</button>
        <button name="then" value="reset" class="ghost danger" onclick="return confirm('Put this design back to how it was made?')">Reset</button>
    </div>
</form>

<aside class="tplprev">
    <div class="prevtop">
        <div class="chips" id="docKind">@foreach ($previews as $k => $label)<button type="button" class="{{ $loop->first ? 'on' : '' }}" data-k="{{ $k }}">{{ $label }}</button>@endforeach</div>
        <span class="muted" id="prevState">Preview</span>
    </div>
    <div class="sheetwrap" id="sheetWrap"><iframe id="sheet" title="Document preview" class="sheet"></iframe></div>
    <div class="muted" style="font-size:12.5px;margin-top:8px">Shows your newest real document of that kind, or a made-up one if there is none. The saved PDF follows the same design.</div>
</aside>
</div>

<script>
(function () {
    var form = document.getElementById('tplForm'), frame = document.getElementById('sheet'), wrap = document.getElementById('sheetWrap'), state = document.getElementById('prevState');
    var kind = 'invoice', timer = null, seq = 0, url = @json(route('settings.templates.preview'));
    function fit() { var w = wrap.clientWidth, k = Math.min(1, w / 794); frame.style.transform = 'scale(' + k + ')'; wrap.style.height = Math.round(1123 * k) + 'px'; }
    function draw() {
        var mine = ++seq, fd = new FormData(form); fd.append('doc', kind); state.textContent = 'Updating…';
        fetch(url, { method: 'POST', body: fd, headers: { 'Accept': 'text/html' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
            .then(function (h) { if (mine === seq) { frame.srcdoc = h; state.textContent = 'Live preview'; } })
            .catch(function () { if (mine === seq) { state.textContent = 'Preview unavailable'; } });
    }
    function later() { clearTimeout(timer); timer = setTimeout(draw, 350); }
    form.addEventListener('input', later); form.addEventListener('change', later);
    document.getElementById('docKind').addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) { return; }
        [].forEach.call(this.children, function (x) { x.classList.remove('on'); }); b.classList.add('on'); kind = b.dataset.k; draw();
    });
    window.addEventListener('resize', fit); fit(); draw();
})();
</script>
@endsection
