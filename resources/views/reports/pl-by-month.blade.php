@extends('layouts.main')
@section('title', 'Profit & Loss by Month')
@section('content')
@php
    use App\Support\Money;
    $cols = array_keys($pl['months']);
    $cell = fn ($vals, $k) => $vals === null ? '' : Money::format($vals[$k] ?? '0.00');
    $sections = [['income', 'Income'], ['cogs', 'Cost of Goods Sold'], ['expense', 'Expense'], ['other_income', 'Other Income'], ['other_expense', 'Other Expense']];
@endphp
@include('reports._head', ['title' => 'Profit & Loss by Month', 'subtitle' => $reports->longDate($from).' through '.$reports->longDate($to), 'mode' => 'range'])
@if (count($pl['months']) >= 36)<div class="flash bad">Only the first 36 months are shown. Pick a shorter period to see the rest.</div>@endif
<div class="card tbl" style="overflow-x:auto"><table>
    <thead><tr><th></th>@foreach ($pl['months'] as $label)<th class="num">{{ $label }}</th>@endforeach<th class="num">Total</th></tr></thead>
    <tbody>
    @foreach ($sections as [$name, $title])
        @continue(($name === 'cogs' && ! $pl['has_cogs']) || (in_array($name, ['other_income', 'other_expense'], true) && ! $pl['has_other']))
        <tr><td colspan="{{ count($cols) + 2 }}"><strong style="text-transform:uppercase">{{ $title }}</strong></td></tr>
        @foreach ($pl['sections'][$name]['rows'] as $r)
            <tr @if ($r['type'] === 'total') style="font-weight:600" @endif>
                <td style="padding-left:{{ 8 + max(0, $r['level'] - 1) * 20 }}px;@if ($r['type'] === 'group') font-weight:600 @endif" @if ($r['type'] === 'group') colspan="{{ count($cols) + 2 }}" @endif>@if ($r['url'])<a href="{{ $r['url'] }}">{{ $r['label'] }}</a>@else{{ $r['label'] }}@endif</td>
                @if ($r['type'] !== 'group')
                    @foreach ($cols as $k)<td class="num">{{ $cell($r['vals'], $k) }}</td>@endforeach<td class="num">{{ $cell($r['vals'], '_total') }}</td>
                @endif
            </tr>
        @endforeach
        <tr style="font-weight:700;border-top:1px solid #999"><td>Total {{ $title }}</td>@foreach ($cols as $k)<td class="num">{{ $cell($pl['sections'][$name]['total'], $k) }}</td>@endforeach<td class="num">{{ $cell($pl['sections'][$name]['total'], '_total') }}</td></tr>
        @if ($name === 'cogs')
            <tr style="font-weight:700"><td>Gross Profit</td>@foreach ($cols as $k)<td class="num">{{ $cell($pl['gross'], $k) }}</td>@endforeach<td class="num">{{ $cell($pl['gross'], '_total') }}</td></tr>
        @endif
        @if ($name === 'expense')
            <tr style="font-weight:700"><td>Net Ordinary Income</td>@foreach ($cols as $k)<td class="num">{{ $cell($pl['net_ordinary'], $k) }}</td>@endforeach<td class="num">{{ $cell($pl['net_ordinary'], '_total') }}</td></tr>
        @endif
    @endforeach
    @if ($pl['has_other'])
        <tr style="font-weight:700"><td>Net Other Income</td>@foreach ($cols as $k)<td class="num">{{ $cell($pl['net_other'], $k) }}</td>@endforeach<td class="num">{{ $cell($pl['net_other'], '_total') }}</td></tr>
    @endif
    <tr style="font-weight:700;border-top:2px solid #333"><td>Net Income</td>@foreach ($cols as $k)<td class="num">{{ $cell($pl['net_income'], $k) }}</td>@endforeach<td class="num">{{ $cell($pl['net_income'], '_total') }}</td></tr>
    </tbody>
</table></div>
@endsection
