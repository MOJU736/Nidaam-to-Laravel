@extends('layouts.main')
@section('title', $direction === 'in' ? 'Money In' : 'Money Out')
@section('content')
@php $in = $direction === 'in'; @endphp
<div class="bar"><h1 style="margin:0">{{ $in ? 'Money In' : 'Money Out' }}</h1><a class="muted" href="{{ route('entries.index') }}">&larr; Journal Entries</a></div>
<form class="card" method="POST" action="{{ route('entries.simple', $direction) }}" style="max-width:640px">
    @csrf
    <p class="muted" style="margin-top:0">{{ $in ? 'Money received into a bank or cash account - income, a loan, capital put in.' : 'Money paid out of a bank or cash account - an expense, a loan repayment, drawings.' }}</p>
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <div class="row2">
        <div><label for="bank_ledger_id">{{ $in ? 'Received into *' : 'Paid from *' }}</label>
            <select id="bank_ledger_id" name="bank_ledger_id" required><option value="">Choose a bank or cash account...</option>@foreach ($banks as $a)<option value="{{ $a['v'] }}" @disabled($a['g']) @selected((string) old('bank_ledger_id') === (string) $a['v'])>{{ str_repeat("\u{00A0}\u{00A0}\u{00A0}", $a['d']).$a['t'] }}</option>@endforeach</select></div>
        <div><label for="category_ledger_id">{{ $in ? 'For (income, loan, capital...) *' : 'For (expense, loan, drawings...) *' }}</label>
            <select id="category_ledger_id" name="category_ledger_id" required><option value="">Choose an account...</option>@foreach ($categories as $a)@continue(str_starts_with($a['v'], 'party:'))<option value="{{ $a['v'] }}" @disabled($a['g']) @selected((string) old('category_ledger_id') === (string) $a['v'])>{{ str_repeat("\u{00A0}\u{00A0}\u{00A0}", $a['d']).$a['t'] }}</option>@endforeach</select></div>
    </div>
    <div class="row2">
        <div><label for="date">Date *</label><input id="date" type="date" name="date" required value="{{ old('date', now()->format('Y-m-d')) }}"></div>
        <div><label for="amount">Amount *</label><input id="amount" name="amount" inputmode="decimal" required value="{{ old('amount') }}" placeholder="0.00"></div>
    </div>
    @if ($projects)
        <label for="project_tag">Project <span class="muted">(optional)</span></label>
        <select id="project_tag" name="project_tag"><option value="">None</option>
            @foreach ($projects as $group => $opts)<optgroup label="{{ $group }}">@foreach ($opts as $v => $t)<option value="{{ $v }}" @selected(old('project_tag') === $v)>{{ $t }}</option>@endforeach</optgroup>@endforeach
        </select>
    @endif
    <label for="memo">Memo</label><input id="memo" name="memo" maxlength="500" value="{{ old('memo') }}" placeholder="Optional - what is this for?">
    <div class="actions" style="margin-top:20px"><button>Record</button><button name="save_and_new" value="1" class="ghost">Save &amp; new</button><a class="btn ghost" href="{{ route('entries.index') }}">Cancel</a></div>
</form>
@endsection
