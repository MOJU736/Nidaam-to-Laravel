<!doctype html>
@php
    // $rows is what a report downloads as CSV: company, title, dates, a blank line, then a header row and data rows.
    // $st is the company's document look (colours, font, logo), so reports and item statements match its invoices.
    $preview = $preview ?? false;
    $st ??= app(\App\Services\Documents\DocumentStyle::class)->resolve();
    $cols = max(array_map('count', $rows) ?: [1]);
    $fs = $cols > 7 ? 8.5 : min($st['fs'], 10.5);
    $isNum = fn ($v) => $v !== '' && $v !== null && preg_match('/^-?[\d,]+(\.\d+)?$/', trim((string) $v));
    $titleRows = 0;
    foreach ($rows as $r) { if (count($r) > 1 || (count($r) === 1 && ($r[0] ?? '') === '')) break; $titleRows++; }
    $titleRows = min($titleRows, 3);
    $onColour = function ($hex) { $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2)); return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#111111' : '#ffffff'; };
    $headText = $onColour($st['head']);
    $f = $st['footer'];
    $contact = array_filter([$f['address'], $f['phone'], $f['email'], $f['website']]);
    $heads = array_slice($rows, 0, $titleRows);
    $numCol = [];  // a column is right-aligned when most of its filled cells are numbers
    foreach (array_slice($rows, $titleRows) as $r) { if (count($r) < 2) { continue; } for ($c = 1; $c < $cols; $c++) { $v = $r[$c] ?? ''; if ($v !== '' && $v !== null) { $numCol[$c][] = $isNum($v); } } }
    $numCol = array_map(fn ($a) => count(array_filter($a)) * 2 >= count($a) && count($a) > 1, $numCol);
@endphp
<html><head><meta charset="utf-8"><title>Report</title>
<style>
    @page { margin: 30px 34px 62px 34px; }
    body { font-family: {!! $st['font'] !!}; font-size: {{ $fs }}px; color: #232733; line-height: 1.45; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    .top { border-bottom: 3px solid {{ $st['second'] }}; padding-bottom: 10px; margin-bottom: 14px; }
    .top td { vertical-align: top; }
    .logo { height: {{ round($st['logo_px'] * .6) }}px; }
    .co { font-size: {{ $fs + 2.5 }}px; font-weight: bold; color: {{ $st['main'] }}; }
    h1 { font-size: {{ $fs + 9 }}px; margin: 0; color: {{ $st['main'] }}; letter-spacing: .02em; }
    .sub { color: #6a7180; margin-top: 3px; }
    td { padding: 5px 7px; border-bottom: 1px solid #e6e9ef; vertical-align: top; }
    .r { text-align: right; white-space: nowrap; }
    .h td { background: {{ $st['head'] }}; color: {{ $headText }}; font-weight: bold; font-size: {{ round($fs - 1, 1) }}px; text-transform: uppercase; letter-spacing: .05em; border-bottom: 0; padding: 7px 7px; }
    .t td { font-weight: bold; background: {{ $st['row'] }}; border-top: 1px solid {{ $st['main'] }}; }
    .g td { font-weight: bold; color: {{ $st['main'] }}; padding-top: 14px; border-bottom: 2px solid {{ $st['second'] }}; font-size: {{ $fs + 1 }}px; }
    .z td { background: {{ $st['row'] }}; }
    .foot { @if ($preview) margin-top: 28px; @else position: fixed; left: 0; right: 0; bottom: -42px; @endif border-top: 1px solid #dfe3ea; padding-top: 7px; color: #6a7180; font-size: {{ round($fs - 1.5, 1) }}px; }
    .foot td { border: 0; padding: 0; }
    .pg { text-align: right; } .pg:after { content: "Page " counter(page); }
    @if ($st['layout'] === 'ledger')
    td { border: 1px solid #555; } .h td { border-color: #222; } .z td { background: #fff; } .top, .top td { border: 0; } .top { border-bottom: 2px solid #222; }
    @elseif (in_array($st['layout'], ['minimal', 'elegant'], true))
    .h td { background: none; color: {{ $st['main'] }}; border-bottom: 2px solid {{ $st['main'] }}; } .z td { background: #fff; } .t td { background: none; border-top: 2px solid {{ $st['main'] }}; }
    @elseif ($st['layout'] === 'card')
    .h td { background: {{ $st['row'] }}; color: {{ $st['main'] }}; border-bottom: 2px solid {{ $st['main'] }}; } .z td { background: #fff; }
    @elseif ($st['layout'] === 'bold')
    .h td { background: {{ $st['main'] }}; color: {{ $onColour($st['main']) }}; } .top { border-bottom: 10px solid {{ $st['main'] }}; } td { font-weight: bold; }
    @endif
    @if ($preview)
    html { background: #e9e8f3; } body { width: 690px; margin: 0 auto; padding: 36px 36px 40px; background: #fff; min-height: 600px; box-shadow: 0 2px 14px rgba(40, 36, 90, .18); }
    @endif
</style></head><body>
<table class="top"><tr>
    <td style="border:0;width:{{ $st['logo'] ? '110px' : '0' }}">@if ($st['logo'])<img class="logo" src="{{ $st['logo'] }}">@endif</td>
    <td style="border:0">
        @foreach ($heads as $i => $r)
            @if ($i === 0)<div class="co">{{ $r[0] }}</div>@elseif ($i === 1)<h1>{{ $r[0] }}</h1>@else<div class="sub">{{ $r[0] }}</div>@endif
        @endforeach
    </td>
</tr></table>
<table>
@php $zebra = 0; @endphp
@foreach (array_slice($rows, $titleRows) as $n => $r)
    @php
        $first = (string) ($r[0] ?? '');
        $single = count($r) === 1 && $first !== '';
        $isHead = $n === 0 || (isset($rows[$titleRows + $n - 1]) && count($rows[$titleRows + $n - 1]) === 1 && ($rows[$titleRows + $n - 1][0] ?? '') === '' && count($r) > 1);
        $isTotal = preg_match('/^\s*(total|net|gross|balance|statement ending|opening)/i', $first) && ! $single;
        if ($isHead || $single) { $zebra = 0; } else { $zebra++; }
    @endphp
    @if ($single)<tr class="g"><td colspan="{{ $cols }}">{{ $first }}</td></tr>
    @elseif (count($r) === 0 || ($first === '' && count(array_filter($r, fn ($x) => $x !== '' && $x !== null)) === 0))<tr><td colspan="{{ $cols }}" style="border:0">&nbsp;</td></tr>
    @else
        <tr class="{{ $isHead ? 'h' : ($isTotal ? 't' : ($zebra % 2 === 0 ? 'z' : '')) }}">@for ($c = 0; $c < $cols; $c++)@php $v = $r[$c] ?? ''; @endphp<td class="{{ $c > 0 && ($isNum($v) || ($isHead && $c > 0 && $cols > 2)) ? 'r' : '' }}">{{ $v }}</td>@endfor</tr>
    @endif
@endforeach
</table>
<div class="foot"><table><tr>
    <td>{{ $contact ? implode('   ·   ', $contact) : ($heads[0][0] ?? '') }}</td>
    @unless ($preview)<td class="pg"></td>@endunless
</tr></table></div>
</body></html>
