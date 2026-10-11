@php
    // $d = the document; $st = the company's look (DocumentStyle::resolve()).
    $hide = $st['hide'];
    $word = fn ($key, $fallback) => $st['words'][$key] ?? $fallback;
    $kind = $d['kind'] ?? 'other';
    $title = $d['title'];
    foreach (['invoice' => 'title_invoice', 'quotation' => 'title_quotation', 'credit' => 'title_credit', 'receipt' => 'title_receipt', 'statement' => 'title_statement'] as $k => $w) {
        if ($kind === $k && ! empty($st['words'][$w])) { $title = $st['words'][$w]; }
    }
    $onColour = function ($hex) { $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2)); return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#111111' : '#ffffff'; };
    $headText = $onColour($st['head']); $bandText = $onColour($st['main']);
    $columns = collect($d['columns'] ?? [])->map(function ($c) use ($st, $word) {
        if ($c['k'] === 'description') { $c['h'] = $word('w_desc', $c['h']); }
        if ($c['k'] === 'qty') { $c['h'] = $word('w_qty', $st['qty_label'] ?? $c['h']); }
        if ($c['k'] === 'price') { $c['h'] = $word('w_price', $st['price_label'] ?? $c['h']); }
        if ($c['k'] === 'amount') { $c['h'] = $word('w_amount', $c['h']); }
        return $c;
    })->all();
    $rows = $d['rows'] ?? [];
    if (! empty($st['rows']) && $columns && count($rows) < $st['rows'] && ($d['pad'] ?? false)) { for ($i = count($rows); $i < $st['rows']; $i++) { $rows[] = []; } }
    $totals = $d['totals'] ?? [];
    foreach ($totals as $i => $t) { if ($t[2] && $i === count($totals) - 1 && ! empty($st['words']['w_total']) && in_array($kind, ['invoice', 'quotation', 'credit'], true)) { $totals[$i][0] = $st['words']['w_total']; } }
    $showSign = in_array($kind, ['invoice', 'quotation', 'credit', 'receipt', 'remittance'], true);
    $showReceived = in_array($kind, ['invoice', 'quotation'], true) && ! in_array('received', $hide, true);
    $f = $st['footer'];
    $contact = array_filter([$f['address'], $f['phone'], $f['email'], $f['website']]);
    $logoAlign = $st['logo_pos'];
@endphp
<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $title }} {{ $d['number'] ?? '' }}</title>
<style>
    @page { margin: 26px 32px; }
    body { font-family: {!! $st['font'] !!}; font-size: {{ $st['fs'] }}px; color: #2b2b2b; line-height: 1.45; }
    table { border-collapse: collapse; width: 100%; }
    .muted { color: #5c6470; } .r { text-align: right; white-space: nowrap; }
    .co { font-size: {{ $st['fs'] + 3.5 }}px; font-weight: bold; text-transform: uppercase; color: {{ $st['main'] }}; letter-spacing: .03em; }
    h1.title { font-size: {{ $st['fs'] + 10 }}px; letter-spacing: .06em; color: {{ $st['main'] }}; margin: 0; }
    .logo { height: {{ $st['logo_px'] }}px; margin-bottom: 4px; }
    .head td { vertical-align: top; }
    /* layouts */
    .band { background: {{ $st['main'] }}; color: {{ $bandText }}; padding: 14px 16px; margin-bottom: 14px; }
    .band .co, .band h1.title { color: {{ $bandText }}; }
    .center { text-align: center; border-bottom: 2px solid {{ $st['second'] }}; padding-bottom: 10px; margin-bottom: 10px; }
    .center h1.title { margin-top: 6px; }
    .line { border-bottom: 2px solid {{ $st['second'] }}; padding-bottom: 10px; }
    .stripe { border-left: 8px solid {{ $st['second'] }}; padding-left: 12px; }
    .meta td { padding: 2px 0; } .meta td.l { color: #5c6470; width: 92px; white-space: nowrap; }
    .lines { margin-top: 16px; border: 1px solid #c7cdd6; }
    .lines th { background: {{ $st['head'] }}; color: {{ $headText }}; padding: 7px 8px; font-size: {{ $st['fs'] - 1 }}px; text-transform: uppercase; text-align: left; }
    .lines th.r { text-align: right; }
    .lines td { padding: 6px 8px; border-top: 1px solid #dfe3ea; vertical-align: top; }
    .lines tbody tr:nth-child(even) td { background: {{ $st['row'] }}; }
    .totals { margin-top: 10px; width: 46%; margin-left: 54%; } .totals td { padding: 3px 8px; }
    .totals tr.b td { font-weight: bold; border-top: 2px solid {{ $st['main'] }}; font-size: {{ $st['fs'] + 1.5 }}px; color: {{ $st['main'] }}; }
    .big { margin-top: 14px; border: 2px solid {{ $st['second'] }}; padding: 10px 14px; } .big td { padding: 2px 0; } .big .v { font-size: {{ $st['fs'] + 5.5 }}px; font-weight: bold; text-align: right; }
    .note { margin-top: 12px; } .words { margin-top: 8px; font-style: italic; }
    .sign td { vertical-align: bottom; padding-top: 18px; }
    .sigline { border-top: 1px solid #8a93a0; margin-top: 6px; padding-top: 3px; display: inline-block; min-width: 190px; color: #5c6470; }
    .recv { border: 1px solid #8a93a0; height: 62px; width: 210px; padding: 4px 6px; color: #5c6470; font-size: {{ $st['fs'] - 1.5 }}px; }
    .foot { margin-top: 22px; color: #555; font-size: {{ round(($st['fs'] - 1) * $f['size'] / 100, 1) }}px; text-align: {{ $f['align'] }}; position: relative; top: {{ $f['y'] }}px; }
    .stamp { display: inline-block; border: 2px solid #b3261e; color: #b3261e; padding: 2px 10px; font-weight: bold; letter-spacing: .1em; margin-top: 6px; }
</style></head>
<body>

@php
    $logoImg = $st['logo'] ? '<img class="logo" src="'.$st['logo'].'">' : '';
    $companyBlock = '<div class="co">'.e($d['company']['name']).'</div>'
        .(! empty($d['company']['address']) ? '<div class="muted" style="color:inherit;opacity:.85">'.nl2br(e($d['company']['address'])).'</div>' : '')
        .(! empty($d['company']['email']) ? '<div class="muted" style="color:inherit;opacity:.85">'.e($d['company']['email']).'</div>' : '');
    $stamp = ! empty($d['stamp']) ? '<div class="stamp">'.e($d['stamp']).'</div>' : '';
@endphp

@if ($st['layout'] === 'center')
    <div class="center">
        @if ($logoImg)<div style="text-align:{{ $logoAlign }}">{!! $logoImg !!}</div>@endif
        {!! $companyBlock !!}<h1 class="title">{{ $title }}</h1>{!! $stamp !!}
    </div>
@elseif ($st['layout'] === 'band')
    <div class="band"><table class="head"><tr>
        <td style="width:58%">@if ($logoImg)<div style="text-align:{{ $logoAlign }}">{!! $logoImg !!}</div>@endif {!! $companyBlock !!}</td>
        <td style="text-align:right"><h1 class="title">{{ $title }}</h1>{!! $stamp !!}</td>
    </tr></table></div>
@else
    <div class="{{ $st['layout'] === 'stripe' ? 'stripe' : 'line' }}"><table class="head"><tr>
        <td style="width:58%">@if ($logoImg)<div style="text-align:{{ $logoAlign }}">{!! $logoImg !!}</div>@endif {!! $companyBlock !!}</td>
        <td style="text-align:right"><h1 class="title">{{ $title }}</h1>{!! $stamp !!}</td>
    </tr></table></div>
@endif

<table class="head" style="margin-top:16px"><tr>
    <td style="width:55%">
        @if (! empty($d['party']))<div class="muted">{{ $d['party_label'] }}</div><div><strong>{{ $d['party']['name'] }}</strong></div>
            @if (! empty($d['party']['address']))<div>{!! nl2br(e($d['party']['address'])) !!}</div>@endif
            @if (! empty($d['party']['phone']))<div>{{ $d['party']['phone'] }}</div>@endif
            @if (! empty($d['party']['email']))<div>{{ $d['party']['email'] }}</div>@endif
        @endif
    </td>
    <td><table class="meta">@foreach ($d['meta'] as [$l, $v])<tr><td class="l">{{ $l }}</td><td><strong>{{ $v }}</strong></td></tr>@endforeach</table></td>
</tr></table>

@if (! empty($d['big']))
    <div class="big"><table>@foreach ($d['big'] as [$l, $v, $isBig])<tr><td>{{ $l }}</td><td class="{{ $isBig ? 'v' : 'r' }}">{{ $v }}</td></tr>@endforeach</table></div>
@endif

@if ($columns)
    <table class="lines">
        <thead><tr>@foreach ($columns as $c)<th class="{{ ($c['align'] ?? '') === 'r' ? 'r' : '' }}" @if (! empty($c['w'])) style="width:{{ $c['w'] }}" @endif>{{ $c['h'] }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($rows as $row)
            <tr @if (! empty($row['_bold'])) style="font-weight:bold" @endif>@foreach ($columns as $c)<td class="{{ ($c['align'] ?? '') === 'r' ? 'r' : '' }}">{!! nl2br(e((string) ($row[$c['k']] ?? ''))) !!}@if ($c['k'] === 'description' && ! empty($row['sub']))<div class="muted" style="font-size:{{ $st['fs'] - 1.5 }}px">{{ $row['sub'] }}</div>@endif</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($columns) }}" class="muted" style="text-align:center">{{ $d['empty'] ?? 'Nothing to show.' }}</td></tr>
        @endforelse
        </tbody>
    </table>
@endif

@if ($totals)
    <table class="totals">@foreach ($totals as [$l, $v, $bold])<tr class="{{ $bold ? 'b' : '' }}"><td>{{ $l }}</td><td class="r">{{ $v }}</td></tr>@endforeach</table>
@endif
@if (! empty($d['total_raw']) && ! in_array('words', $hide, true))<div class="words">{{ \App\Support\NumberWords::money($d['total_raw']) }}</div>@endif

@if (! empty($d['notes']) && ! in_array('notes', $hide, true))<div class="note"><strong>Notes</strong><br>{!! nl2br(e($d['notes'])) !!}</div>@endif
@if ((! empty($d['bank']) || $st['payment_extra']) && ! in_array('payment', $hide, true))
    <div class="note"><strong>Payment details</strong><br>@foreach ($d['bank'] ?? [] as [$l, $v]){{ $l }}: {{ $v }}<br>@endforeach @foreach ($st['payment_extra'] as $line){{ $line }}<br>@endforeach</div>
@endif

@if ($showSign && ($st['signature'] || $st['stamp'] || $st['signature_name'] || $showReceived))
    <table class="sign"><tr>
        <td style="width:55%">
            <div style="position:relative;height:{{ max(40, (int) (64 * max($st['sig']['size'], $st['stampbox']['size']) / 100)) + 6 + max(0, $st['sig']['y'], $st['stampbox']['y']) }}px">
                @if ($st['signature'] && ! in_array('signature', $hide, true))<img src="{{ $st['signature'] }}" style="position:absolute;left:{{ $st['sig']['x'] }}px;top:{{ $st['sig']['y'] }}px;height:{{ (int) (64 * $st['sig']['size'] / 100) }}px">@endif
                @if ($st['stamp'] && ! in_array('stamp', $hide, true))<img src="{{ $st['stamp'] }}" style="position:absolute;left:{{ 120 + $st['stampbox']['x'] }}px;top:{{ $st['stampbox']['y'] }}px;height:{{ (int) (64 * $st['stampbox']['size'] / 100) }}px">@endif
            </div>
            <div class="sigline">{{ $word('w_sign', 'Authorized Signature') }}</div>
            @if ($st['signature_name'] || $st['signature_title'])<div>@if ($st['signature_name'])<strong>{{ $st['signature_name'] }}</strong>@endif @if ($st['signature_title'])<br>{{ $st['signature_title'] }}@endif</div>@endif
        </td>
        <td style="text-align:right">@if ($showReceived)<div class="recv" style="margin-left:auto;text-align:left">{{ $word('w_received', 'Received by') }}</div>@endif</td>
    </tr></table>
@endif

<div class="foot">
    @if (! empty($d['footer']) || $st['footer_note'])<div style="margin-bottom:4px">{{ $st['footer_note'] ?? $d['footer'] }}</div>@endif
    @if ($contact && ! in_array('contact', $hide, true))<div>{{ implode('  ·  ', $contact) }}</div>@endif
</div>
</body></html>
