@extends('layouts.main')
@section('title', $title ?? 'Profit & Loss by Month')
@section('content')
@php
    use App\Support\Money;
    $cols = array_keys($pl['months']);
    $title = $title ?? 'Profit & Loss by Month';
    $compare = $compare ?? false;
    $sub = $sub ?? ($reports->longDate($from).' through '.$reports->longDate($to));
    $ncols = count($cols) + 2;
    $cell = fn ($vals, $k) => $vals === null ? '' : Money::format($vals[$k] ?? '0.00');
    $tail = function ($vals) use ($compare) {
        if (! $compare) { return '<td class="num">'.($vals === null ? '' : App\Support\Money::format($vals['_total'] ?? '0.00')).'</td>'; }
        if ($vals === null) { return '<td></td><td></td>'; }
        $d = App\Support\Money::sub($vals['cur'] ?? 0, $vals['prev'] ?? 0); $p = abs((float) ($vals['prev'] ?? 0)) > 0.004 ? (float) $d / abs((float) $vals['prev']) * 100 : null;
        return '<td class="num">'.App\Support\Money::format($d).'</td><td class="num">'.($p === null ? '' : ($p >= 0 ? '+' : '').number_format($p, 1).'%').'</td>';
    };
    $sections = [['income', 'Income'], ['cogs', 'Cost of Goods Sold'], ['expense', 'Expense'], ['other_income', 'Other Income'], ['other_expense', 'Other Expense']];
@endphp
@include('reports._head', ['title' => $title, 'subtitle' => $sub, 'mode' => 'range', 'before' => $before ?? '', 'extra' => $extra ?? []])
@if (! $cols)<div class="card"><p class="muted" style="margin:0">{{ $emptyText ?? 'Nothing to show for these dates.' }}</p></div>@else
@if (count($pl['months']) >= 36)<div class="flash bad">Only the first 36 months are shown. Pick a shorter period to see the rest.</div>@endif
<div class="card tbl" style="overflow-x:auto"><table>
    <thead><tr><th></th>@foreach ($pl['months'] as $label)<th class="num">{{ $label }}</th>@endforeach @if ($compare)<th class="num">Change</th><th class="num">%</th>@else<th class="num">Total</th>@endif</tr></thead>
    <tbody>
    @foreach ($sections as [$name, $title])
        @continue(($name === 'cogs' && ! $pl['has_cogs']) || (in_array($name, ['other_income', 'other_expense'], true) && ! $pl['has_other']))
        <tr><td colspan="{{ $ncols + ($compare ? 1 : 0) }}"><strong style="text-transform:uppercase">{{ $title }}</strong></td></tr>
        @foreach ($pl['sections'][$name]['rows'] as $r)
            <tr @if ($r['type'] === 'total') style="font-weight:600" @endif>
                <td style="padding-left:{{ 8 + max(0, $r['level'] - 1) * 20 }}px;@if ($r['type'] === 'group') font-weight:600 @endif" @if ($r['type'] === 'group') colspan="{{ $ncols + ($compare ? 1 : 0) }}" @endif>@if ($r['url'])<a href="{{ $r['url'] }}">{{ $r['label'] }}</a>@else{{ $r['label'] }}@endif</td>
                @if ($r['type'] !== 'group')
                    @foreach ($cols as $k)<td class="num">{{ $cell($r['vals'], $k) }}</td>@endforeach{!! $tail($r['vals']) !!}
                @endif
            </tr>
        @endforeach
        <tr style="font-weight:700;border-top:1px solid #999"><td>Total {{ $title }}</td>@foreach ($cols as $k)<td class="num">{{ $cell($pl['sections'][$name]['total'], $k) }}</td>@endforeach{!! $tail($pl['sections'][$name]['total']) !!}</tr>
        @if ($name === 'cogs')
            <tr style="font-weight:700"><td>Gross Profit</td>@foreach ($cols as $k)<td class="num">{{ $cell($pl['gross'], $k) }}</td>@endforeach{!! $tail($pl['gross']) !!}</tr>
        @endif
        @if ($name === 'expense')
            <tr style="font-weight:700"><td>Net Ordinary Income</td>@foreach ($cols as $k)<td class="num">{{ $cell($pl['net_ordinary'], $k) }}</td>@endforeach{!! $tail($pl['net_ordinary']) !!}</tr>
        @endif
    @endforeach
    @if ($pl['has_other'])
        <tr style="font-weight:700"><td>Net Other Income</td>@foreach ($cols as $k)<td class="num">{{ $cell($pl['net_other'], $k) }}</td>@endforeach{!! $tail($pl['net_other']) !!}</tr>
    @endif
    <tr style="font-weight:700;border-top:2px solid #333"><td>Net Income</td>@foreach ($cols as $k)<td class="num">{{ $cell($pl['net_income'], $k) }}</td>@endforeach{!! $tail($pl['net_income']) !!}</tr>
    </tbody>
</table></div>
@endif
@endsection
