@extends('layouts.main')
@section('title', 'Account Statement')
@section('content')
@php use App\Support\Money; @endphp
@if ($ledger)
    @include('reports._head', ['title' => 'Account Statement', 'subtitle' => $ledger->name.' · '.$reports->longDate($from).' through '.$reports->longDate($to), 'mode' => 'range', 'noFilter' => true])
@else
    <div class="bar"><div><h1 style="margin:0">Account Statement</h1><div class="muted">Every movement in one account, with a running balance</div></div><a class="btn ghost" href="{{ route('reports.index') }}">All reports</a></div>
@endif
<form class="card noprint" method="GET" action="{{ route('reports.statement') }}" style="margin-bottom:16px">
    <div class="rfilter">
        <div style="min-width:280px"><label for="account">Account</label>
            <select id="account" name="account" required><option value="">Choose an account...</option>@foreach ($accounts as $id => $n)<option value="{{ $id }}" @selected($ledger && $ledger->id === $id)>{{ $n }}</option>@endforeach</select></div>
        <div><label for="from2">From</label><input id="from2" type="date" name="from" value="{{ $from }}"></div>
        <div><label for="to2">To</label><input id="to2" type="date" name="to" value="{{ $to }}"></div>
        <button>Show</button>
    </div>
</form>
@if ($st)
    @php $n = $st['nature']; @endphp
    @if (! empty($n['fy_start']))<p class="muted">Income and expense accounts start again each financial year, so the opening balance only counts from {{ Money::date($n['fy_start']) }}.</p>@endif
    <div class="card tbl"><table>
        <thead><tr><th>Date</th><th>Type</th><th>Num</th><th>Name</th><th>Memo</th><th>Split</th><th class="num">{{ $n['in'] }}</th><th class="num">{{ $n['out'] }}</th><th class="num">Balance</th></tr></thead>
        <tbody>
            <tr><td colspan="8"><strong>Opening balance</strong></td><td class="num"><strong>{{ Money::format($st['opening']) }}</strong></td></tr>
            @forelse ($st['rows'] as $r)
                <tr><td>{{ Money::date($r['date']) }}</td><td><a href="{{ $r['url'] }}">{{ $r['type'] }}</a></td><td>{{ $r['num'] }}</td><td>{{ $r['name'] }}</td><td>{{ $r['memo'] }}</td><td>{{ $r['split'] }}</td>
                    <td class="num">{{ $r['in'] !== null ? Money::format($r['in']) : '' }}</td><td class="num">{{ $r['out'] !== null ? Money::format($r['out']) : '' }}</td><td class="num">{{ Money::format($r['balance']) }}</td></tr>
            @empty
                <tr><td colspan="9" class="muted" style="text-align:center;padding:24px">No transactions in this period.</td></tr>
            @endforelse
            <tr><td colspan="6"><strong>Total / closing balance</strong></td><td class="num"><strong>{{ Money::format($st['total_in']) }}</strong></td><td class="num"><strong>{{ Money::format($st['total_out']) }}</strong></td><td class="num"><strong>{{ Money::format($st['closing']) }}</strong></td></tr>
        </tbody>
    </table></div>
@endif
@endsection
