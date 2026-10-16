@extends('layouts.main')
@section('title', 'Receive money for a client')
@section('content')
<div class="bar"><h1>Receive money for a client</h1><a class="btn ghost" href="{{ route('commissions.index') }}">Back</a></div>
<form class="card" method="POST" action="{{ route('commissions.store') }}" style="max-width:640px">@csrf
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <p class="muted" style="margin-top:0">The money goes into your bank or cash account and is held as "Due to Client" until you settle it (you keep your commission, the rest goes to the client).</p>
    <label for="party_name">Who is it for (the client or agent)</label>
    <input id="party_name" name="party_name" list="agents" maxlength="255" value="{{ old('party_name') }}" required>
    <datalist id="agents">@foreach ($agents as $a)<option value="{{ $a }}">@endforeach</datalist>
    <div class="row2">
        <div><label for="date">Date received</label><input id="date" type="date" name="date" value="{{ old('date', date('Y-m-d')) }}" required></div>
        <div><label for="received_amount">Amount received</label><input id="received_amount" name="received_amount" inputmode="decimal" value="{{ old('received_amount') }}" required></div>
    </div>
    <label for="received_ledger_id">Paid into</label>
    <select id="received_ledger_id" name="received_ledger_id" required><option value="">Choose a bank or cash account…</option>@foreach ($banks as $b)<option value="{{ $b['id'] }}" @selected((int) old('received_ledger_id') === $b['id'])>{{ $b['name'] }}</option>@endforeach</select>
    <label for="notes">Note (optional)</label><input id="notes" name="notes" maxlength="500" value="{{ old('notes') }}">
    <div class="actions" style="margin-top:18px"><button>Save</button></div>
</form>
@endsection
