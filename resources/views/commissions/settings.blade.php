@extends('layouts.main')
@section('title', 'Commission settings')
@section('content')
<div class="bar"><h1>Commission settings</h1><a class="btn ghost" href="{{ route('commissions.index') }}">Back</a></div>
<form class="card" method="POST" action="{{ route('commissions.settings.save') }}" style="max-width:700px">@csrf
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <p class="muted" style="margin-top:0">Choose the accounts commissions post to. Leave one empty and it is found by name (or made) the first time it is needed.</p>
    @foreach (['due_to_client' => 'Due to Client (money held for clients)', 'commission_income' => 'Commission Income', 'tax_expense' => 'Tax Expense', 'tax_payable' => 'Taxes Payable'] as $k => $label)
        <label for="{{ $k }}_ledger_id">{{ $label }}</label>
        <select id="{{ $k }}_ledger_id" name="{{ $k }}_ledger_id"><option value="">Automatic</option>@foreach ($ledgers as $l)<option value="{{ $l->id }}" @selected((int) old($k.'_ledger_id', $s->{$k.'_ledger_id'}) === (int) $l->id)>{{ $l->name }}</option>@endforeach</select>
    @endforeach
    <div class="row2">
        <div><label for="default_percent">Usual commission %</label><input id="default_percent" name="default_percent" inputmode="decimal" value="{{ old('default_percent', (float) $s->default_percent ? rtrim(rtrim((string) $s->default_percent, '0'), '.') : '') }}"></div>
        <div><label for="prefix">Number prefix</label><input id="prefix" name="prefix" maxlength="20" value="{{ old('prefix', $s->prefix) }}"></div>
    </div>
    <div class="row2">
        <div><label for="receive_tax_percent">Tax % on money received</label><input id="receive_tax_percent" name="receive_tax_percent" inputmode="decimal" value="{{ old('receive_tax_percent', (float) $s->receive_tax_percent ? rtrim(rtrim((string) $s->receive_tax_percent, '0'), '.') : '0') }}"></div>
        <div><label for="settle_tax_percent">Tax % on the commission</label><input id="settle_tax_percent" name="settle_tax_percent" inputmode="decimal" value="{{ old('settle_tax_percent', (float) $s->settle_tax_percent ? rtrim(rtrim((string) $s->settle_tax_percent, '0'), '.') : '0') }}"></div>
    </div>
    <div class="actions" style="margin-top:18px"><button>Save</button></div>
</form>
@endsection
