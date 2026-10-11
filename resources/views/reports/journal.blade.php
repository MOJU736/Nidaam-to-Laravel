@extends('layouts.main')
@section('title', 'Journal')
@section('content')
@php use App\Support\Money; $sub = \Illuminate\Support\Carbon::parse($from)->format('F j, Y').' through '.\Illuminate\Support\Carbon::parse($to)->format('F j, Y'); @endphp
@include('reports._head', ['title' => 'Journal', 'subtitle' => $sub, 'mode' => 'range'])
<p class="muted noprint" style="margin-top:0">Every transaction and the accounts it went to. This is the accountant's view, so it shows Debit and Credit; in each transaction they are equal.</p>
<div class="card tbl"><table>
    <thead><tr><th>Date</th><th>Type</th><th>Num</th><th>Name / Account</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
    <tbody>
    @forelse ($entries as $e)
        <tr style="background:rgba(127,127,127,.08)"><td><strong>{{ Money::date($e['date']) }}</strong></td><td><a href="{{ $e['url'] }}">{{ $e['type'] }}</a></td><td>{{ $e['num'] }}</td><td>{{ $e['name'] }}</td><td></td><td></td><td></td></tr>
        @foreach ($e['lines'] as $l)
            <tr><td></td><td></td><td></td><td style="padding-left:24px">{{ $l['account'] }}</td><td>{{ $l['memo'] }}</td>
                <td class="num">{{ $l['debit'] !== null ? Money::format($l['debit']) : '' }}</td><td class="num">{{ $l['credit'] !== null ? Money::format($l['credit']) : '' }}</td></tr>
        @endforeach
    @empty
        <tr><td colspan="7" class="muted" style="text-align:center;padding:24px">No transactions in this period.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
