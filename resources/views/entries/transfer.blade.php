@extends('layouts.main')
@section('title', 'Transfer')
@section('content')
<a class="back" href="{{ route('entries.index') }}">&larr; Journal Entries</a>
<div class="bar"><h1>Transfer</h1></div>
<form class="card" method="POST" action="{{ route('entries.transfer') }}" style="max-width:640px">
    @csrf
    <p class="muted" style="margin-top:0">Move money between two of your own bank or cash accounts.</p>
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <div class="row2">
        <div><label for="from_ledger_id">From *</label>
            <select id="from_ledger_id" name="from_ledger_id" required><option value="">Choose an account...</option>@foreach ($banks as $a)<option value="{{ $a['v'] }}" @disabled($a['g']) @selected((string) old('from_ledger_id') === (string) $a['v'])>{{ str_repeat("\u{00A0}\u{00A0}\u{00A0}", $a['d']).$a['t'] }}</option>@endforeach</select></div>
        <div><label for="to_ledger_id">To *</label>
            <select id="to_ledger_id" name="to_ledger_id" required><option value="">Choose an account...</option>@foreach ($banks as $a)<option value="{{ $a['v'] }}" @disabled($a['g']) @selected((string) old('to_ledger_id') === (string) $a['v'])>{{ str_repeat("\u{00A0}\u{00A0}\u{00A0}", $a['d']).$a['t'] }}</option>@endforeach</select></div>
    </div>
    <div class="row2">
        <div><label for="date">Date *</label><input id="date" type="date" name="date" required value="{{ old('date', now()->format('Y-m-d')) }}"></div>
        <div><label for="amount">Amount *</label><input id="amount" name="amount" inputmode="decimal" required value="{{ old('amount') }}" placeholder="0.00"></div>
    </div>
    <label for="memo">Memo</label><input id="memo" name="memo" maxlength="500" value="{{ old('memo') }}" placeholder="Optional - what is this transfer for?">
    <div class="actions" style="margin-top:20px"><button>Record transfer</button><button name="save_and_new" value="1" class="ghost">Save &amp; new</button><a class="btn ghost" href="{{ route('entries.index') }}">Cancel</a></div>
</form>
@endsection
