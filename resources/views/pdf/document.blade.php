@php
    // $d = the document; $st = the company's look (DocumentStyle::resolve()); $preview = shown in the template editor, not printed.
    $preview = $preview ?? false;
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
    $lastBold = null; foreach ($totals as $i => $t) { if ($t[2]) { $lastBold = $i; } }
    $showSign = in_array($kind, ['invoice', 'quotation', 'credit', 'receipt', 'remittance'], true);
    $showReceived = in_array($kind, ['invoice', 'quotation'], true) && ! in_array('received', $hide, true);
    $f = $st['footer'];
    $contact = array_filter([$f['address'], $f['phone'], $f['email'], $f['website']]);
    $logoAlign = $st['logo_pos'];
    $hasPay = (! empty($d['bank']) || $st['payment_extra']) && ! in_array('payment', $hide, true);
    $hasNotes = ! empty($d['notes']) && ! in_array('notes', $hide, true);
    $fs = $st['fs'];
@endphp
<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $title }} {{ $d['number'] ?? '' }}</title>
<style>
    @page { margin: 34px 38px 74px 38px; }
    body { font-family: {!! $st['font'] !!}; font-size: {{ $fs }}px; color: #232733; line-height: 1.5; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    .muted { color: #6a7180; } .r { text-align: right; white-space: nowrap; }
    .cap { font-size: {{ round($fs - 2.5, 1) }}px; text-transform: uppercase; letter-spacing: .09em; color: #7a8190; font-weight: bold; margin-bottom: 3px; }
    .co { font-size: {{ $fs + 4 }}px; font-weight: bold; color: {{ $st['main'] }}; letter-spacing: .01em; }
    .coinfo { color: #5f6676; font-size: {{ round($fs - 1, 1) }}px; line-height: 1.45; margin-top: 2px; }
    h1.title { font-size: {{ $fs + 13 }}px; letter-spacing: .08em; color: {{ $st['main'] }}; margin: 0; font-weight: bold; }
    .num { font-size: {{ $fs + 1 }}px; color: #5f6676; margin-top: 2px; }
    .logo { height: {{ $st['logo_px'] }}px; margin-bottom: 6px; }
    .head td { vertical-align: top; }
    /* layouts */
    .band { background: {{ $st['main'] }}; color: {{ $bandText }}; padding: 18px 22px; margin-bottom: 4px; }
    .band .logo { background: #ffffff; padding: 6px 10px; border-radius: 6px; }
    .band .co, .band h1.title, .band .num, .band .coinfo { color: {{ $bandText }}; } .band .coinfo, .band .num { opacity: .85; }
    .center { text-align: center; padding-bottom: 12px; border-bottom: 3px solid {{ $st['second'] }}; }
    .center h1.title { margin-top: 8px; }
    .line { padding-bottom: 14px; border-bottom: 3px solid {{ $st['second'] }}; }
    .stripe { border-left: 9px solid {{ $st['second'] }}; padding: 2px 0 2px 16px; }
    .meta td { padding: 3px 0; vertical-align: top; } .meta td.l { color: #6a7180; padding-right: 14px; white-space: nowrap; } .meta td.v { text-align: right; font-weight: bold; }
    .panel { background: {{ $st['row'] }}; padding: 10px 16px; border-radius: 6px; }
    .lines { margin-top: 20px; }
    .lines th { background: {{ $st['head'] }}; color: {{ $headText }}; padding: 8px 10px; font-size: {{ round($fs - 2, 1) }}px; text-transform: uppercase; letter-spacing: .06em; text-align: left; border: 1px solid {{ $st['head'] }}; }
    .lines th.r { text-align: right; }
    .lines td { padding: 9px 10px; border-bottom: 1px solid #e6e9ef; vertical-align: top; }
    .lines tbody tr:nth-child(even) td { background: {{ $st['row'] }}; }
    .totals { margin-top: 12px; width: 44%; margin-left: 56%; } .totals td { padding: 5px 10px; color: #5f6676; }
    .totals td.r { color: #232733; }
    .totals tr.b td { font-weight: bold; color: #232733; border-top: 1px solid #cfd4de; }
    .totals tr.last td { background: {{ $st['main'] }}; color: {{ $bandText }}; font-size: {{ $fs + 2 }}px; padding: 9px 10px; border: 1px solid {{ $st['main'] }}; }
    .big { margin-top: 18px; border-left: 6px solid {{ $st['second'] }}; background: {{ $st['row'] }}; padding: 12px 16px; } .big td { padding: 2px 0; } .big .v { font-size: {{ $fs + 9 }}px; font-weight: bold; text-align: right; color: {{ $st['main'] }}; }
    .words { margin-top: 10px; font-style: italic; color: #5f6676; }
    .box { vertical-align: top; padding: 10px 14px; border: 1px solid #e1e5ec; border-radius: 6px; }
    .sign td { vertical-align: bottom; padding-top: 22px; }
    .sigline { border-top: 1px solid #8a93a0; margin-top: 6px; padding-top: 4px; display: inline-block; min-width: 200px; color: #6a7180; font-size: {{ round($fs - 1, 1) }}px; }
    .recv { border: 1px solid #b7bdc8; border-radius: 6px; height: 62px; width: 210px; padding: 5px 8px; color: #6a7180; font-size: {{ round($fs - 1.5, 1) }}px; }
    .foot { @if ($preview) margin-top: 34px; @else position: fixed; left: 0; right: 0; bottom: {{ -48 - $f['y'] }}px; @endif border-top: 1px solid #dfe3ea; padding-top: 8px; color: #6a7180; font-size: {{ round(($fs - 1.5) * $f['size'] / 100, 1) }}px; text-align: {{ $f['align'] }}; }
    .pg { text-align: right; font-size: {{ round($fs - 2, 1) }}px; color: #9aa1af; }
    .pg:after { content: "Page " counter(page); }
    .stamp { display: inline-block; border: 2px solid #b3261e; color: #b3261e; padding: 2px 12px; font-weight: bold; letter-spacing: .14em; margin-top: 8px; font-size: {{ $fs + 1 }}px; }
    .band .stamp { border-color: {{ $bandText }}; color: {{ $bandText }}; }
    @if ($preview)
    html { background: #e9e8f3; } body { width: 690px; margin: 0 auto; padding: 38px 40px 40px; background: #fff; min-height: 930px; box-shadow: 0 2px 14px rgba(40, 36, 90, .18); }
    @endif
</style></head>
<body>

@php
    $logoImg = $st['logo'] ? '<img class="logo" src="'.$st['logo'].'">' : '';
    $companyBlock = '<div class="co">'.e($d['company']['name']).'</div>'
        .(! empty($d['company']['address']) ? '<div class="coinfo">'.nl2br(e($d['company']['address'])).'</div>' : '')
        .(! empty($d['company']['email']) ? '<div class="coinfo">'.e($d['company']['email']).'</div>' : '');
    $stamp = ! empty($d['stamp']) ? '<div class="stamp">'.e($d['stamp']).'</div>' : '';
    $titleBlock = '<h1 class="title">'.e($title).'</h1>'.(! empty($d['number']) ? '<div class="num">'.e($d['number']).'</div>' : '').$stamp;
@endphp

@if ($st['layout'] === 'center')
    <div class="center">
        @if ($logoImg)<div style="text-align:{{ $logoAlign }}">{!! $logoImg !!}</div>@endif
        {!! $companyBlock !!}{!! $titleBlock !!}
    </div>
@elseif ($st['layout'] === 'band')
    <div class="band"><table class="head"><tr>
        <td style="width:58%">@if ($logoImg)<div style="text-align:{{ $logoAlign }}">{!! $logoImg !!}</div>@endif {!! $companyBlock !!}</td>
        <td style="text-align:right">{!! $titleBlock !!}</td>
    </tr></table></div>
@else
    <div class="{{ $st['layout'] === 'stripe' ? 'stripe' : 'line' }}"><table class="head"><tr>
        <td style="width:58%">@if ($logoImg)<div style="text-align:{{ $logoAlign }}">{!! $logoImg !!}</div>@endif {!! $companyBlock !!}</td>
        <td style="text-align:right">{!! $titleBlock !!}</td>
    </tr></table></div>
@endif

<table class="head" style="margin-top:22px"><tr>
    <td style="width:52%; padding-right:20px">
        @if (! empty($d['party']))<div class="cap">{{ $d['party_label'] }}</div><div style="font-size:{{ $fs + 2.5 }}px"><strong>{{ $d['party']['name'] }}</strong></div>
            @if (! empty($d['party']['address']))<div class="muted">{!! nl2br(e($d['party']['address'])) !!}</div>@endif
            @if (! empty($d['party']['phone']))<div class="muted">{{ $d['party']['phone'] }}</div>@endif
            @if (! empty($d['party']['email']))<div class="muted">{{ $d['party']['email'] }}</div>@endif
        @endif
    </td>
    <td><div class="panel"><table class="meta">@foreach ($d['meta'] as [$l, $v])<tr><td class="l">{{ $l }}</td><td class="v">{{ $v }}</td></tr>@endforeach</table></div></td>
</tr></table>

@if (! empty($d['big']))
    <div class="big"><table>@foreach ($d['big'] as [$l, $v, $isBig])<tr><td class="{{ $isBig ? '' : 'muted' }}">{{ $l }}</td><td class="{{ $isBig ? 'v' : 'r' }}">{{ $v }}</td></tr>@endforeach</table></div>
@endif

@if ($columns)
    <table class="lines">
        <thead><tr>@foreach ($columns as $c)<th class="{{ ($c['align'] ?? '') === 'r' ? 'r' : '' }}" @if (! empty($c['w'])) style="width:{{ $c['w'] }}" @endif>{{ $c['h'] }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($rows as $row)
            <tr @if (! empty($row['_bold'])) style="font-weight:bold" @endif>@foreach ($columns as $c)<td class="{{ ($c['align'] ?? '') === 'r' ? 'r' : '' }}">{!! nl2br(e((string) ($row[$c['k']] ?? ''))) !!}@if ($c['k'] === 'description' && ! empty($row['sub']))<div class="muted" style="font-size:{{ $fs - 1.5 }}px">{{ $row['sub'] }}</div>@endif{!! empty($row) ? '&nbsp;' : '' !!}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($columns) }}" class="muted" style="text-align:center;padding:18px">{{ $d['empty'] ?? 'Nothing to show.' }}</td></tr>
        @endforelse
        </tbody>
    </table>
@endif

@if ($totals)
    <table class="totals">@foreach ($totals as $i => [$l, $v, $bold])<tr class="{{ $bold ? 'b' : '' }} {{ $i === $lastBold ? 'last' : '' }}"><td>{{ $l }}</td><td class="r">{{ $v }}</td></tr>@endforeach</table>
@endif
@if (! empty($d['total_raw']) && ! in_array('words', $hide, true))<div class="words">{{ \App\Support\NumberWords::money($d['total_raw']) }}</div>@endif

@if ($hasNotes || $hasPay)
    <table style="margin-top:20px"><tr>
        @if ($hasNotes)<td class="box" style="width:{{ $hasPay ? '48%' : '100%' }}"><div class="cap">Notes</div>{!! nl2br(e($d['notes'])) !!}</td>@endif
        @if ($hasNotes && $hasPay)<td style="width:4%"></td>@endif
        @if ($hasPay)<td class="box" style="width:{{ $hasNotes ? '48%' : '100%' }}"><div class="cap">Payment details</div>@foreach ($d['bank'] ?? [] as [$l, $v])<span class="muted">{{ $l }}:</span> {{ $v }}<br>@endforeach @foreach ($st['payment_extra'] as $line){{ $line }}<br>@endforeach</td>@endif
    </tr></table>
@endif

@if ($showSign && ($st['signature'] || $st['stamp'] || $st['signature_name'] || $showReceived))
    <table class="sign"><tr>
        <td style="width:55%">
            <div style="position:relative;height:{{ max(40, (int) (64 * max($st['sig']['size'], $st['stampbox']['size']) / 100)) + 6 + max(0, $st['sig']['y'], $st['stampbox']['y']) }}px">
                @if ($st['signature'] && ! in_array('signature', $hide, true))<img src="{{ $st['signature'] }}" style="position:absolute;left:{{ $st['sig']['x'] }}px;top:{{ $st['sig']['y'] }}px;height:{{ (int) (64 * $st['sig']['size'] / 100) }}px">@endif
                @if ($st['stamp'] && ! in_array('stamp', $hide, true))<img src="{{ $st['stamp'] }}" style="position:absolute;left:{{ 120 + $st['stampbox']['x'] }}px;top:{{ $st['stampbox']['y'] }}px;height:{{ (int) (64 * $st['stampbox']['size'] / 100) }}px">@endif
            </div>
            <div class="sigline">{{ $word('w_sign', 'Authorized Signature') }}</div>
            @if ($st['signature_name'] || $st['signature_title'])<div>@if ($st['signature_name'])<strong>{{ $st['signature_name'] }}</strong>@endif @if ($st['signature_title'])<br><span class="muted">{{ $st['signature_title'] }}</span>@endif</div>@endif
        </td>
        <td style="text-align:right">@if ($showReceived)<div class="recv" style="margin-left:auto;text-align:left">{{ $word('w_received', 'Received by') }}</div>@endif</td>
    </tr></table>
@endif

<div class="foot">
    @if (! empty($d['footer']) || $st['footer_note'])<div style="margin-bottom:3px;color:#3d4350;font-weight:bold">{{ $st['footer_note'] ?? $d['footer'] }}</div>@endif
    @if ($contact && ! in_array('contact', $hide, true))<div>{{ implode('   ·   ', $contact) }}</div>@endif
    @unless ($preview)<div class="pg"></div>@endunless
</div>
</body></html>
