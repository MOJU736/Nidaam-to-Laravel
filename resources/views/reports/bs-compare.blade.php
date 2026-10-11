@extends('layouts.main')
@section('title', 'Balance Sheet Comparison')
@section('content')
@php
    use App\Support\Money;
    $row = function ($r) {
        $pad = 8 + max(0, $r['level'] - 1) * 20;
        $label = ! empty($r['url']) ? '<a href="'.e($r['url']).'">'.e($r['label']).'</a>' : e($r['label']);
        if ($r['type'] === 'group') { return '<tr class="group"><td style="padding-left:'.$pad.'px">'.$label.'</td><td></td><td></td><td></td></tr>'; }

        return '<tr class="'.$r['type'].'"><td style="padding-left:'.$pad.'px">'.$label.'</td><td class="num">'.Money::format($r['a']).'</td><td class="num">'.Money::format($r['b']).'</td><td class="num">'.Money::format(Money::sub($r['a'], $r['b'])).'</td></tr>';
    };
    $grand = fn ($l, $p) => '<tr class="grand"><td>'.$l.'</td><td class="num">'.Money::format($p[0]).'</td><td class="num">'.Money::format($p[1]).'</td><td class="num">'.Money::format(Money::sub($p[0], $p[1])).'</td></tr>';
@endphp
@include('reports._head', ['title' => 'Balance Sheet Comparison', 'subtitle' => 'As of '.$reports->longDate($c['asof']).', compared with '.$reports->longDate($c['other']), 'mode' => 'asof', 'before' => $before, 'extra' => $extra])
<div class="card"><table class="qbr" style="max-width:none">
    <thead><tr><th></th><th class="num">{{ date('M j, Y', strtotime($c['asof'])) }}</th><th class="num">{{ date('M j, Y', strtotime($c['other'])) }}</th><th class="num">Change</th></tr></thead>
    <tr class="sec"><td colspan="4">Assets</td></tr>
    @foreach ($c['asset_rows'] as $r){!! $row($r) !!}@endforeach
    {!! $grand('Total Assets', $c['assets']) !!}
    <tr class="sec"><td colspan="4">Liabilities &amp; Equity</td></tr>
    @foreach ($c['liab_rows'] as $r){!! $row($r) !!}@endforeach
    {!! $grand('Total Liabilities &amp; Equity', $c['liab_eq']) !!}
</table></div>
@endsection
