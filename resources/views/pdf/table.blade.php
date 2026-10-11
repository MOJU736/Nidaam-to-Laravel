<!doctype html>
@php
    // $rows is what a report downloads as CSV: company, title, dates, a blank line, then a header row and data rows.
    $cols = max(array_map('count', $rows) ?: [1]);
    $isNum = fn ($v) => $v !== '' && $v !== null && preg_match('/^-?[\d,]+(\.\d+)?$/', trim((string) $v));
    $titleRows = 0;
    foreach ($rows as $r) { if (count($r) > 1 || (count($r) === 1 && ($r[0] ?? '') === '')) break; $titleRows++; }
    $titleRows = min($titleRows, 3);
@endphp
<html><head><meta charset="utf-8"><title>Report</title>
<style>
    @page { margin: 26px 30px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: {{ $cols > 7 ? 8.5 : 10.5 }}px; color: #2b2b2b; }
    h1 { font-size: 17px; margin: 0; color: #1f3f99; } .co { font-size: 12px; font-weight: bold; text-transform: uppercase; color: #1f3f99; } .sub { color: #5c6470; margin-bottom: 12px; }
    table { border-collapse: collapse; width: 100%; } td { padding: 4px 6px; border-bottom: 1px solid #e3e6ec; vertical-align: top; }
    .r { text-align: right; white-space: nowrap; } .h td { background: #2f5fd0; color: #fff; font-weight: bold; } .t td { font-weight: bold; border-top: 1px solid #8a93a0; } .g td { font-weight: bold; padding-top: 10px; }
</style></head><body>
@foreach (array_slice($rows, 0, $titleRows) as $i => $r)
    @if ($i === 0)<div class="co">{{ $r[0] }}</div>@elseif ($i === 1)<h1>{{ $r[0] }}</h1>@else<div class="sub">{{ $r[0] }}</div>@endif
@endforeach
<table>
@foreach (array_slice($rows, $titleRows) as $n => $r)
    @php
        $first = (string) ($r[0] ?? '');
        $single = count($r) === 1 && $first !== '';
        $isHead = $n === 0 || (isset($rows[$titleRows + $n - 1]) && count($rows[$titleRows + $n - 1]) === 1 && ($rows[$titleRows + $n - 1][0] ?? '') === '' && count($r) > 1);
        $isTotal = preg_match('/^\s*(total|net|gross|balance|statement ending|opening)/i', $first) && ! $single;
    @endphp
    @if ($single)<tr class="g"><td colspan="{{ $cols }}">{{ $first }}</td></tr>
    @elseif (count($r) === 0 || ($first === '' && count(array_filter($r, fn ($x) => $x !== '' && $x !== null)) === 0))<tr><td colspan="{{ $cols }}" style="border:0">&nbsp;</td></tr>
    @else
        <tr class="{{ $isHead ? 'h' : ($isTotal ? 't' : '') }}">@for ($c = 0; $c < $cols; $c++)@php $v = $r[$c] ?? ''; @endphp<td class="{{ $c > 0 && ($isNum($v) || ($isHead && $c > 0 && $cols > 2)) ? 'r' : '' }}">{{ $v }}</td>@endfor</tr>
    @endif
@endforeach
</table></body></html>
