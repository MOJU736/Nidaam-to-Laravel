@extends('layouts.main')
@section('title', 'General Ledger')
@section('content')
@php use App\Support\Money; @endphp
@include('reports._head', ['title' => 'General Ledger', 'subtitle' => $reports->longDate($from).' through '.$reports->longDate($to), 'mode' => 'range'])
<p class="muted noprint" style="margin-top:0">Every account that moved in these dates, in chart order: the balance at the start, each transaction and the balance after it.</p>
@forelse ($gl['accounts'] as $a)
    @php $st = $a['st']; @endphp
    <div class="card tbl" style="margin-bottom:12px">
        <div style="margin-bottom:4px"><a href="{{ route('reports.statement', ['account' => $a['id'], 'from' => $from, 'to' => $to]) }}"><strong>{{ $a['name'] }}</strong></a> <span class="muted">· {{ $a['group'] }}</span></div>
        <table>
            <thead><tr><th>Date</th><th>Type</th><th>Num</th><th>Name</th><th>Memo / Split</th><th class="num">Amount</th><th class="num">Balance</th></tr></thead>
            <tbody>
                <tr><td></td><td colspan="4" class="muted">Balance at the start</td><td></td><td class="num">{{ Money::format($st['opening']) }}</td></tr>
                @foreach ($st['rows'] as $r)
                    <tr><td>{{ Money::date($r['date']) }}</td><td>{{ $r['type'] }}</td><td><a href="{{ $r['url'] }}">{{ $r['num'] }}</a></td><td>{{ $r['name'] }}</td><td>{{ trim($r['memo'].($r['split'] !== '' ? ($r['memo'] !== '' ? ' · ' : '').$r['split'] : '')) }}</td>
                        <td class="num">{{ $r['in'] !== null ? Money::format($r['in']) : '('.ltrim(Money::format($r['out']), '-').')' }}</td><td class="num">{{ Money::format($r['balance']) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot><tr><th colspan="5">Total {{ $a['name'] }}</th><th class="num">{{ Money::format($a['net']) }}</th><th class="num">{{ Money::format($st['closing']) }}</th></tr></tfoot>
        </table>
    </div>
@empty
    <div class="card"><p class="muted" style="margin:0">No account moved in these dates.</p></div>
@endforelse
@endsection
