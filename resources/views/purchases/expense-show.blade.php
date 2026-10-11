@extends('layouts.main')
@section('title', 'Expense '.$purchases->expenseLabel($e->number))
@section('content')
@php use App\Support\Money; @endphp
@include('purchases._tabs')
<div class="bar">
    <div><h1 style="margin:0">Expense {{ $purchases->expenseLabel($e->number) }} <span class="pill {{ $e->status === 'void' ? 'overdue' : '' }}">{{ $e->status === 'void' ? 'Void' : 'Paid' }}</span></h1><a class="muted" href="{{ route('expenses.index') }}">&larr; Quick expenses</a></div>
    <div class="actions">
        @if ($e->status === 'paid')
            <form class="inline" method="POST" action="{{ route('expenses.void', $e->id) }}" onsubmit="return confirm('Void this expense? Its journal entry is reversed.')">@csrf<button class="danger">Void</button></form>
        @else
            <form class="inline" method="POST" action="{{ route('expenses.destroy', $e->id) }}" onsubmit="return confirm('Delete this voided expense for good?')">@csrf @method('DELETE')<button class="danger">Delete</button></form>
        @endif
    </div>
</div>
<div class="card"><dl class="kv"><dt>Date</dt><dd>{{ Money::date($e->date) }}</dd><dt>Amount</dt><dd><strong>{{ Money::format($e->amount) }}</strong></dd><dt>Expense</dt><dd>{{ $e->expense_ledger_name }}@if ($e->item_name) <span class="muted">(item: {{ $e->item_name }})</span>@endif</dd>
    <dt>Paid from</dt><dd>{{ $e->bank_ledger_name }}</dd>@if ($e->method)<dt>Method</dt><dd>{{ $e->method }}</dd>@endif @if ($e->reference)<dt>Reference</dt><dd>{{ $e->reference }}</dd>@endif @if ($e->notes)<dt>Notes</dt><dd>{{ $e->notes }}</dd>@endif</dl></div>
@endsection
