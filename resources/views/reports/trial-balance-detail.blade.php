@extends('layouts.main')
@section('title', 'Trial Balance (detail)')
@section('content')
@php use App\Support\Money; $sd = fn ($v) => Money::isZero($v) ? '' : Money::format(ltrim($v, '-')).(Money::cmp($v, 0) < 0 ? ' Cr' : ' Dr'); @endphp
@include('reports._head', ['title' => 'Trial Balance (detail)', 'subtitle' => 'As of '.$reports->longDate($asof), 'mode' => 'asof', 'extra' => ['view' => 'detail']])
<p class="muted noprint" style="margin-top:0"><a href="{{ route('reports.trial-balance', ['asof' => $asof]) }}">Simple view</a> · opening balance, all debits, all credits and the closing balance of every group and account.</p>
@unless ($tb['balanced'])<div class="flash bad">Total debits ({{ Money::format($tb['debits']) }}) and total credits ({{ Money::format($tb['credits']) }}) are not equal.</div>@endunless
<div class="card tbl"><table>
    <thead><tr><th>Account</th><th class="num">Opening balance</th><th class="num">Debits</th><th class="num">Credits</th><th class="num">Closing balance</th></tr></thead>
    <tbody>
    @foreach ($tb['rows'] as $r)
        <tr @if ($r['type'] === 'group') style="font-weight:600;background:var(--bg)" @endif>
            <td style="padding-left:{{ 8 + max(0, $r['level'] - 1) * 20 }}px">@if ($r['type'] === 'ledger')<a href="{{ route('reports.statement', ['account' => $r['id']]) }}">{{ $r['label'] }}</a>@else{{ $r['label'] }}@endif</td>
            <td class="num">{{ $sd($r['v']['op']) }}</td><td class="num">{{ Money::isZero($r['v']['dr']) ? '' : Money::format($r['v']['dr']) }}</td><td class="num">{{ Money::isZero($r['v']['cr']) ? '' : Money::format($r['v']['cr']) }}</td><td class="num">{{ $sd($r['v']['cl']) }}</td>
        </tr>
    @endforeach
    </tbody>
    <tfoot><tr><th>TOTAL (including opening balances)</th><th></th><th class="num">{{ Money::format($tb['debits']) }}</th><th class="num">{{ Money::format($tb['credits']) }}</th><th>{{ $tb['balanced'] ? 'Balanced' : 'Out of balance' }}</th></tr></tfoot>
</table></div>
@endsection
