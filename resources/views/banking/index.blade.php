@extends('layouts.main')
@section('title', 'Reconcile')
@section('content')
@php use App\Support\Money; @endphp
<div class="bar"><h1 style="margin:0">Reconcile</h1></div>
<p class="muted" style="margin-top:0">Check your books against each bank statement: enter the statement's ending date and balance, then tick every transaction that appears on it until the difference is zero.</p>
@if (! $cards)
    <div class="card muted">No bank or cash accounts yet. In <a href="{{ route('chart') }}">Accounts</a>, edit an account and switch on <em>Bank or cash account</em> or <em>Reconcile with statements</em>.</div>
@else
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px">
    @foreach ($cards as $c)
        @php $l = $c['ledger']; @endphp
        <div class="card">
            <strong>{{ $l->name }}</strong>
            <div style="margin:4px 0 10px">
                @if ($c['draft'])<span class="pill owes">In progress · statement {{ Money::date($c['draft']->statement_date) }}</span>
                @elseif ($c['last'])<span class="pill">Reconciled to {{ Money::date($c['last']->statement_date) }}</span>
                @else<span class="pill">Never reconciled</span>@endif
            </div>
            <dl class="kv"><dt>Balance in books</dt><dd>{{ Money::format($c['book']) }}</dd>@if ($c['last'])<dt>Last statement balance</dt><dd>{{ Money::format($c['last']->statement_balance) }}</dd>@endif</dl>
            <div class="actions" style="margin-top:12px">
                <a class="btn sm" href="{{ route('banking.reconcile', $l->id) }}">{{ $c['draft'] ? 'Continue' : 'Reconcile' }}</a>
                @if ($c['last'])<a class="btn ghost sm" href="{{ route('banking.history', $l->id) }}">History</a>@endif
                <a class="btn ghost sm" href="{{ route('reports.statement', ['account' => $l->id]) }}">Statement</a>
            </div>
        </div>
    @endforeach
    </div>
@endif
@endsection
