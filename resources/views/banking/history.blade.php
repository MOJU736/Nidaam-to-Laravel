@extends('layouts.main')
@section('title', 'Reconciliation history')
@section('content')
@php use App\Support\Money; @endphp
<div class="bar"><div><h1 style="margin:0">Reconciliation history</h1><div class="muted">{{ $ledger->name }}</div></div>
    <div class="actions">@if ($draft)<a class="btn" href="{{ route('banking.rec', $draft->id) }}">Continue reconciling</a>@else<a class="btn" href="{{ route('banking.reconcile', $ledger->id) }}">Reconcile</a>@endif<a class="btn ghost" href="{{ route('banking.index') }}">All accounts</a></div></div>
<div class="card tbl"><table>
    <thead><tr><th>Statement date</th><th class="num">Statement balance</th><th>Finished</th><th>By</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($recs as $r)
        <tr><td>{{ Money::date($r->statement_date) }}</td><td class="num">{{ Money::format($r->statement_balance) }}</td><td>{{ $r->finished_at ? Money::date(substr($r->finished_at, 0, 10)) : '' }}</td><td>{{ $r->finished_by }}</td>
            <td><span class="pill {{ $r->status === 'undone' ? 'overdue' : '' }}">{{ $r->status === 'undone' ? 'Undone' : 'Done' }}</span></td>
            <td style="text-align:right"><a class="btn ghost sm" href="{{ route('banking.report', $r->id) }}">Report</a>@if ($last && $last->id === $r->id && ! $draft)
                <form class="inline" method="POST" action="{{ route('banking.undo', $r->id) }}" onsubmit="return confirm('Undo this reconciliation? Its transactions become uncleared again.')">@csrf<button class="danger sm">Undo</button></form>@endif</td></tr>
    @empty
        <tr><td colspan="6" class="muted" style="text-align:center;padding:24px">No finished reconciliations yet.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
