@extends('layouts.main')
@section('title', 'Reconcile '.$ledger->name)
@section('content')
@php use App\Support\Money; @endphp
<a class="back" href="{{ route('banking.index') }}">&larr; Reconcile</a>
<div class="bar"><h1>Reconcile {{ $ledger->name }}</h1></div>
<form class="card" method="POST" action="{{ route('banking.reconcile', $ledger->id) }}" style="max-width:640px" autocomplete="off">
    @csrf
    <p style="margin-top:0">Take the bank statement for this account and enter what it says at the end.</p>
    <dl class="kv">
        <dt>Beginning balance</dt><dd>{{ Money::format($begin) }} <span class="muted">(everything already cleared)</span></dd>
        @if ($last)<dt>Last reconciled</dt><dd>{{ Money::date($last->statement_date) }}, statement balance {{ Money::format($last->statement_balance) }}</dd>@endif
    </dl>
    <div class="row2">
        <div><label for="statement_date">Statement ending date *</label><input id="statement_date" type="date" name="statement_date" required value="{{ $date }}" @if ($last) min="{{ substr($last->statement_date, 0, 10) }}" @endif></div>
        <div><label for="statement_balance">Statement ending balance *</label><input id="statement_balance" name="statement_balance" inputmode="decimal" required autofocus placeholder="0.00" value="{{ $balance }}"></div>
    </div>
    <p class="muted" style="font-size:13px">If the statement shows an overdrawn balance, type it with a minus sign, e.g. -250.00.</p>
    <div class="actions" style="margin-top:16px"><button>{{ $draft ? 'Update statement' : 'Start reconciling' }}</button><a class="btn ghost" href="{{ $draft ? route('banking.rec', $draft->id) : route('banking.index') }}">Cancel</a></div>
</form>
@endsection
