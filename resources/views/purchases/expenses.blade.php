@extends('layouts.main')
@section('title', 'Quick expenses')
@section('content')
@php use App\Support\Money; @endphp
@include('purchases._tabs')
@php $tot = '0.00'; $ym = date('Y-m'); foreach ($rows as $ex) { if ($ex->status !== 'void' && substr((string) $ex->date, 0, 7) === $ym) { $tot = Money::add($tot, (string) $ex->amount); } } @endphp
<div class="bar"><div><h1>Quick expenses</h1><div class="muted">Everyday costs paid straight from cash or the bank</div></div><a class="btn" href="{{ route('expenses.create') }}">{{ \App\Support\Icon::svg('plus', 16) }}New expense</a></div>
<div class="tiles"><div class="tile"><small>Spent this month</small><b>{{ Money::format($tot) }}</b><span>{{ date('F Y') }}</span></div></div>
<div class="card tbl"><table>
    <thead><tr><th>No.</th><th>Date</th><th>Expense</th><th>Paid from</th><th>Status</th><th class="num">Amount</th></tr></thead>
    <tbody>
    @forelse ($rows as $e)
        <tr><td><a href="{{ route('expenses.show', $e->id) }}">{{ $purchases->expenseLabel($e->number) }}</a></td><td>{{ Money::date($e->date) }}</td>
            <td>{{ $e->item_name ?: $e->expense_ledger_name }}@if ($e->item_name)<div class="muted" style="font-size:13px">{{ $e->expense_ledger_name }}</div>@endif</td><td>{{ $e->bank_ledger_name }}</td>
            <td><span class="pill {{ $e->status === 'void' ? 'overdue' : '' }}">{{ $e->status === 'void' ? 'Void' : 'Paid' }}</span></td><td class="num">{{ Money::format($e->amount) }}</td></tr>
    @empty
        <tr><td colspan="6" class="muted" style="text-align:center;padding:24px">No expenses yet.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
