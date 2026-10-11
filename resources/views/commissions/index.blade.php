@extends('layouts.main')
@section('title', 'Commissions')
@section('content')
@php $m = fn ($v) => \App\Support\Money::format($v); @endphp
<div class="bar"><div><h1 style="margin:0">Commissions</h1><div class="muted">Money received for a client, held as "Due to Client", then settled: the client gets it less your commission.</div></div>
    <div class="actions"><a class="btn" href="{{ route('commissions.create') }}">+ Receive money</a><a class="btn ghost" href="{{ route('reports.commissions') }}">Report</a>@if ($canManage)<a class="btn ghost" href="{{ route('commissions.settings') }}">Settings</a>@endif</div></div>
<div class="grid" style="margin-bottom:16px">
    <div class="stat"><span class="muted">Still owed to clients</span><b>{{ $m($totals['pending']) }}</b><span class="muted">{{ $totals['pending_n'] }} not settled</span></div>
    <div class="stat"><span class="muted">Received in all</span><b>{{ $m($totals['received']) }}</b></div>
    <div class="stat"><span class="muted">Commission earned</span><b>{{ $m($totals['commission']) }}</b></div>
    <div class="stat"><span class="muted">Sent on to clients</span><b>{{ $m($totals['remitted']) }}</b></div>
</div>
@foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
<form method="GET" class="actions" style="margin-bottom:12px;align-items:center">
    @foreach (['' => 'All', 'pending' => 'Waiting to be settled', 'settled' => 'Settled', 'void' => 'Voided'] as $k => $label)<a class="btn sm {{ $status === $k ? '' : 'ghost' }}" href="{{ route('commissions.index', array_filter(['status' => $k, 'q' => $q])) }}">{{ $label }}</a>@endforeach
    <input name="q" value="{{ $q }}" placeholder="Client or note" style="width:220px"><input type="hidden" name="status" value="{{ $status }}"><button class="ghost sm">Search</button>
</form>
<div class="card tbl"><table>
    <thead><tr><th>No.</th><th>Received</th><th>For</th><th>Paid into</th><th class="num">Received</th><th>Status</th><th class="num">Commission</th><th class="num">Sent on</th><th></th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
        <tr>
            <td>{{ $r['label'] }}</td><td>{{ \App\Support\Money::date($r['date']) }}</td><td><strong>{{ $r['party_name'] }}</strong>@if ($r['notes'])<div class="muted" style="font-size:13px">{{ $r['notes'] }}</div>@endif</td><td>{{ $r['received_ledger'] }}</td>
            <td class="num">{{ $m($r['received_amount']) }}</td>
            <td><span class="pill {{ $r['status'] === 'pending' ? 'owes' : ($r['status'] === 'void' ? 'overdue' : '') }}">{{ ['pending' => 'Waiting', 'settled' => 'Settled', 'void' => 'Voided'][$r['status']] ?? $r['status'] }}</span>@if ($r['settle_date'])<div class="muted" style="font-size:13px">{{ \App\Support\Money::date($r['settle_date']) }}</div>@endif</td>
            <td class="num">{{ $r['status'] === 'settled' ? $m($r['commission_amount']) : '–' }}</td><td class="num">{{ $r['status'] === 'settled' ? $m($r['remitted_amount']) : '–' }}</td>
            <td style="white-space:nowrap;text-align:right">
                @if ($r['status'] === 'pending')<a class="btn sm" href="{{ route('commissions.settle-form', $r['id']) }}">Settle</a>@endif
                @if ($canManage && $r['status'] === 'settled')<form class="inline" method="POST" action="{{ route('commissions.unsettle', $r['id']) }}" onsubmit="return confirm('Take this settlement back? The money becomes due to the client again.')">@csrf<button class="ghost sm">Undo settle</button></form>@endif
                @if ($canManage && $r['status'] !== 'void')<form class="inline" method="POST" action="{{ route('commissions.void', $r['id']) }}" onsubmit="return confirm('Void {{ $r['label'] }}? Its entries are removed from the books.')">@csrf<button class="danger sm">Void</button></form>@endif
            </td>
        </tr>
    @empty
        <tr><td colspan="9" class="muted" style="text-align:center;padding:24px">No commission transactions yet. Press "Receive money" when you collect money for a client.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
