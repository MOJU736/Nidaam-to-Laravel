@extends('layouts.main')
@section('title', 'Payment '.\App\Services\Sales\Allocations::docLabel('', $p->number))
@section('content')
@php use App\Support\Money; use App\Services\Sales\Allocations; $label = Allocations::docLabel('', $p->number); @endphp
@include('sales._tabs')
<a class="back" href="{{ route('payments.index') }}">&larr; Payments</a>
<div class="bar">
    <div class="titlerow"><h1>Payment {{ $label }}</h1><span class="pill {{ $p->status === 'void' ? 'overdue' : '' }}">{{ $p->status === 'void' ? 'Void' : 'Received' }}</span></div>
    <div class="actions">
        @include('documents._actions', ['type' => 'receipt', 'id' => $p->id])
        <details class="menu">
            <summary>More <svg class="ic" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></summary>
            <div class="drop">
                @if ($p->status === 'received')
                    <a href="{{ route('payments.edit', $p->id) }}">Edit</a><hr>
                    <form method="POST" action="{{ route('payments.void', $p->id) }}" onsubmit="return confirm('Void this payment? Its journal entry is reversed and the invoices it paid become unpaid again.')">@csrf<button class="danger">Void payment</button></form>
                @else
                    <form method="POST" action="{{ route('payments.destroy', $p->id) }}" onsubmit="return confirm('Delete this voided payment for good?')">@csrf @method('DELETE')<button class="danger">Delete payment</button></form>
                @endif
            </div>
        </details>
    </div>
</div>

<div class="docgrid">
    <div class="card docmeta">
        <div class="wide2"><small>Received from</small><div class="who"><a href="{{ route('customers.show', $p->customer_id) }}">{{ $p->customer_name }}</a></div></div>
        <div><small>Date</small>{{ Money::date($p->date) }}</div>
        <div><small>Deposited to</small>{{ $p->ledger_name }}</div>
        @if ($p->method)<div><small>Method</small>{{ $p->method }}</div>@endif
        @if ($p->reference)<div><small>Reference</small>{{ $p->reference }}</div>@endif
        @if (Money::positive($p->wht_amount ?? 0))<div class="wide2"><small>Tax withheld</small>{{ Money::format($p->wht_amount) }} ({{ rtrim(rtrim((string) $p->wht_rate, '0'), '.') }}%) → {{ $p->wht_ledger_name }}; {{ Money::format(Money::sub($p->amount, $p->wht_amount)) }} reached the bank</div>@endif
        @if ($p->notes)<div class="wide2"><small>Notes</small>{{ $p->notes }}</div>@endif
    </div>
    <div class="card balance {{ $p->status === 'void' ? 'void' : 'settled' }}">
        <small>{{ $p->status === 'void' ? 'Voided payment' : 'Amount received' }}</small>
        <div class="big">{{ Money::format($p->amount) }}</div>
        @if ($p->status === 'received')<dl><dt>Applied to invoices</dt><dd>{{ Money::format($appliedTotal) }}</dd><dt>Kept as credit</dt><dd>{{ Money::format($unapplied) }}</dd></dl>@endif
    </div>
</div>

@if ($apps)
    <h2>Applied to</h2>
    <div class="card tbl"><table>
        <thead><tr><th>Invoice</th><th>Date</th><th class="num">Invoice total</th><th class="num">Applied</th><th></th></tr></thead>
        <tbody>@foreach ($apps as $a)
            <tr><td><a href="{{ route('invoices.show', $a->doc_id) }}">{{ $a->label }}</a></td><td>{{ Money::date($a->date) }}</td><td class="num">{{ Money::format($a->total) }}</td><td class="num">{{ Money::format($a->amount) }}</td>
                <td style="text-align:right">@if ($p->status === 'received')<form class="inline" method="POST" action="{{ route('payments.allocation.remove', $a->id) }}">@csrf<button class="ghost sm">Remove</button></form>@endif</td></tr>
        @endforeach</tbody>
    </table></div>
@endif

@if ($p->status === 'received' && Money::positive($unapplied) && $openDocs)
    <h2>Apply the remaining {{ Money::format($unapplied) }}</h2>
    <form method="POST" action="{{ route('payments.apply', $p->id) }}" class="card tbl">
        @csrf
        <table><thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th class="num">Balance due</th><th class="num" style="width:130px">Apply</th></tr></thead>
            <tbody>@foreach ($openDocs as $d)<tr><td>{{ $d->label }}</td><td>{{ Money::date($d->date) }}</td><td>{{ $d->due_date ? Money::date($d->due_date) : '—' }}</td><td class="num">{{ Money::format($d->balance_due) }}</td>
                <td><input class="num" name="apply[{{ $d->id }}]" inputmode="decimal" placeholder="0.00"></td></tr>@endforeach</tbody></table>
        <div class="actions" style="margin-top:12px"><button>Apply</button></div>
    </form>
@endif
@if ($refunds->count())
    <h2>Refunds</h2>
    <div class="card tbl"><table>
        <thead><tr><th>Date</th><th>Paid from</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody>@foreach ($refunds as $r)
            <tr><td>{{ Money::date($r->date) }}</td><td>{{ $r->ledger_name }}</td><td>{{ $r->method }}</td><td>{{ $r->reference }}</td><td class="num">{{ Money::format($r->amount) }}</td>
                <td style="text-align:right"><form class="inline" method="POST" action="{{ route('credit-notes.refund.delete', $r->id) }}" onsubmit="return confirm('Delete this refund? Its journal entry is reversed.')">@csrf<button class="ghost sm">Delete</button></form></td></tr>
        @endforeach</tbody>
    </table></div>
@endif
@if ($p->status === 'received' && Money::positive($unapplied))
    @include('credits._refund-form', ['action' => route('payments.refund', $p->id), 'remaining' => $unapplied, 'heading' => 'Refund the unapplied '.Money::format($unapplied).' to the customer'])
@endif
@include('documents._email-box', ['type' => 'receipt', 'id' => $p->id])
@endsection
