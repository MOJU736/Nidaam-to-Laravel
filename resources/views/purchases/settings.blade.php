@extends('layouts.main')
@section('title', 'Purchase settings')
@section('content')
@include('purchases._tabs')
<div class="bar"><h1 style="margin:0">Purchase settings</h1></div>
<form class="card" method="POST" action="{{ route('purchases.settings') }}" style="max-width:700px">
    @csrf @method('PUT')
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <label for="ap_group_id" style="margin-top:0">Accounts Payable group</label>
    <select id="ap_group_id" name="ap_group_id"><option value="">Not set</option>@foreach ($groups as $g)<option value="{{ $g->id }}" @selected((string) old('ap_group_id', $s->ap_group_id) === (string) $g->id)>{{ $g->name }}{{ $g->code ? ' ('.$g->code.')' : '' }}</option>@endforeach</select>
    <p class="muted" style="font-size:13px;margin:4px 0 0">New vendors get their own account in this group.</p>
    @foreach ([['tax_ledger_id', 'Purchase tax account', 'Debited with the tax on bills (tax you can claim back).'], ['discount_ledger_id', 'Discounts received account', 'Credited with the discounts suppliers give on bills. Left blank, one is found or created when needed.']] as [$f, $label, $hint])
        <label for="{{ $f }}">{{ $label }}</label>
        <select id="{{ $f }}" name="{{ $f }}"><option value="">Not set</option>@foreach ($ledgers as $l)<option value="{{ $l->id }}" @selected((string) old($f, $s->{$f} ?? '') === (string) $l->id)>{{ $l->code ? $l->code.' - ' : '' }}{{ $l->name }}</option>@endforeach</select>
        <p class="muted" style="font-size:13px;margin:4px 0 0">{{ $hint }}</p>
    @endforeach
    <label for="bill_prefix">Bill number prefix</label><input id="bill_prefix" name="bill_prefix" maxlength="20" value="{{ old('bill_prefix', $s->bill_prefix) }}" style="max-width:160px">
    <div class="actions" style="margin-top:20px"><button>Save settings</button></div>
</form>
@endsection
