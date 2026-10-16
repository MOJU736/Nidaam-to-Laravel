@extends('layouts.main')
@section('title', 'Sales settings')
@section('content')
@include('sales._tabs')
<div class="bar"><h1>Sales settings</h1></div>
<form class="card" method="POST" action="{{ route('sales.settings') }}" style="max-width:720px">
    @csrf @method('PUT')
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <h2 style="margin-top:0">Accounts</h2>
    <label for="ar_group_id">Accounts Receivable group</label>
    <select id="ar_group_id" name="ar_group_id"><option value="">Not set</option>@foreach ($groups as $g)<option value="{{ $g->id }}" @selected((string) old('ar_group_id', $s->ar_group_id) === (string) $g->id)>{{ $g->name }}{{ $g->code ? ' ('.$g->code.')' : '' }}</option>@endforeach</select>
    <p class="muted" style="font-size:13px;margin:4px 0 0">New customers get their own account in this group.</p>
    @foreach ([['sales_ledger_id', 'Sales (income) account', 'Credited by invoice lines whose item has no income account of its own.'], ['tax_ledger_id', 'Sales tax account', 'Credited with the tax on invoices.'], ['discount_ledger_id', 'Discounts allowed account', 'Debited with the discounts given on invoices. Left blank, one is found or created when needed.']] as [$f, $label, $hint])
        <label for="{{ $f }}">{{ $label }}</label>
        <select id="{{ $f }}" name="{{ $f }}"><option value="">Not set</option>@foreach ($ledgers as $l)<option value="{{ $l->id }}" @selected((string) old($f, $s->{$f} ?? '') === (string) $l->id)>{{ $l->code ? $l->code.' - ' : '' }}{{ $l->name }}</option>@endforeach</select>
        <p class="muted" style="font-size:13px;margin:4px 0 0">{{ $hint }}</p>
    @endforeach
    @if ($hasBadDebt)
        <label for="bad_debt_ledger_id">Bad debt account</label>
        <select id="bad_debt_ledger_id" name="bad_debt_ledger_id"><option value="">Not set</option>@foreach ($ledgers as $l)<option value="{{ $l->id }}" @selected((string) old('bad_debt_ledger_id', $s->bad_debt_ledger_id ?? '') === (string) $l->id)>{{ $l->code ? $l->code.' - ' : '' }}{{ $l->name }}</option>@endforeach</select>
    @endif
    <h2>Numbering</h2>
    <div class="row2"><div><label for="quotation_prefix" style="margin-top:0">Quotation prefix</label><input id="quotation_prefix" name="quotation_prefix" maxlength="20" value="{{ old('quotation_prefix', $s->quotation_prefix) }}"></div>
        <div><label for="invoice_prefix" style="margin-top:0">Invoice prefix</label><input id="invoice_prefix" name="invoice_prefix" maxlength="20" value="{{ old('invoice_prefix', $s->invoice_prefix) }}"></div></div>
    <h2>Bank details <span class="muted" style="font-weight:400;font-size:13px">(shown on documents)</span></h2>
    <div class="row2">
        <div><label for="bank_name" style="margin-top:0">Bank</label><input id="bank_name" name="bank_name" maxlength="255" value="{{ old('bank_name', $s->bank_name) }}"></div>
        <div><label for="bank_account_name" style="margin-top:0">Account name</label><input id="bank_account_name" name="bank_account_name" maxlength="255" value="{{ old('bank_account_name', $s->bank_account_name) }}"></div>
        <div><label for="bank_account_number">Account number</label><input id="bank_account_number" name="bank_account_number" maxlength="100" value="{{ old('bank_account_number', $s->bank_account_number) }}"></div>
        <div><label for="bank_iban">IBAN</label><input id="bank_iban" name="bank_iban" maxlength="100" value="{{ old('bank_iban', $s->bank_iban) }}"></div>
    </div>
    <div class="actions" style="margin-top:20px"><button>Save settings</button></div>
</form>
@endsection
