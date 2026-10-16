@extends('layouts.main')
@section('title', 'New expense')
@section('content')
@include('purchases._tabs')
<a class="back" href="{{ route('expenses.index') }}">&larr; Quick expenses</a>
<div class="bar"><h1>New expense <span class="muted" style="font-size:15px">{{ $next }}</span></h1></div>
<form class="card" method="POST" action="{{ route('expenses.store') }}" style="max-width:700px">
    @csrf
    <p class="muted" style="margin-top:0">Money paid out at once with no vendor. Buying stock goes through a bill instead, so quantity and cost update.</p>
    @foreach (['date', 'amount', 'ledger_id'] as $k)@if ($errors->has($k))<p class="error">{{ $errors->first($k) }}</p>@endif @endforeach
    <label style="font-weight:400;margin-top:0"><input type="radio" name="expense_type" value="account" @checked(old('expense_type', 'account') === 'account')> By account</label>
    <label style="font-weight:400"><input type="radio" name="expense_type" value="item" @checked(old('expense_type') === 'item')> By item</label>
    <div id="byAccount"><label for="expense_ledger_id">Expense / asset account *</label>
        <select id="expense_ledger_id" name="expense_ledger_id"><option value="">Choose an account...</option>@foreach ($accounts as $id => $n)<option value="{{ $id }}" @selected((string) old('expense_ledger_id') === (string) $id)>{{ $n }}</option>@endforeach</select></div>
    <div id="byItem" hidden><label for="item_id">Item *</label>
        <select id="item_id" name="item_id"><option value="">Choose an item...</option>@foreach ($items as $i)<option value="{{ $i->id }}" @selected((string) old('item_id') === (string) $i->id)>{{ $i->name }}</option>@endforeach</select></div>
    <div class="row2">
        <div><label for="ledger_id">Paid from *</label><select id="ledger_id" name="ledger_id" required><option value="">Choose a bank or cash account...</option>@foreach ($banks as $a)<option value="{{ $a['v'] }}" @disabled($a['g']) @selected((string) old('ledger_id') === (string) $a['v'])>{{ str_repeat("\u{00A0}\u{00A0}\u{00A0}", $a['d']).$a['t'] }}</option>@endforeach</select></div>
        <div><label for="amount">Amount *</label><input id="amount" name="amount" inputmode="decimal" required value="{{ old('amount') }}" placeholder="0.00"></div>
    </div>
    <div class="row2" style="grid-template-columns:1fr 1fr 1fr">
        <div><label for="date">Date *</label><input id="date" type="date" name="date" required value="{{ old('date', now()->format('Y-m-d')) }}"></div>
        <div><label for="method">Method</label><select id="method" name="method"><option value="">—</option>@foreach ($methods as $m)<option value="{{ $m }}" @selected(old('method') === $m)>{{ $m }}</option>@endforeach</select></div>
        <div><label for="reference">Reference</label><input id="reference" name="reference" maxlength="100" value="{{ old('reference') }}"></div>
    </div>
    @if ($projects)<label for="project">Project <span class="muted">(optional - counts as a cost of the project)</span></label><select id="project" name="project"><option value="">None</option>@foreach ($projects as $group => $opts)<optgroup label="{{ $group }}">@foreach ($opts as $v => $tx)<option value="{{ $v }}" @selected(old('project') === $v)>{{ $tx }}</option>@endforeach</optgroup>@endforeach</select>@endif
    <label for="notes">Notes</label><input id="notes" name="notes" maxlength="500" value="{{ old('notes') }}">
    <div class="actions" style="margin-top:20px"><button>Record expense</button><button name="save_and_new" value="1" class="ghost">Save &amp; new</button><a class="btn ghost" href="{{ route('expenses.index') }}">Cancel</a></div>
</form>
<script>
(function () { var radios = document.querySelectorAll('input[name=expense_type]'), a = document.getElementById('byAccount'), i = document.getElementById('byItem');
    function sync() { var item = document.querySelector('input[name=expense_type]:checked').value === 'item'; a.hidden = item; i.hidden = !item; } radios.forEach(function (r) { r.addEventListener('change', sync); }); sync(); })();
</script>
@endsection
