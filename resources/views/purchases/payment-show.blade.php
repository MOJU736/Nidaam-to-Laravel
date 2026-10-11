@extends('layouts.main')
@section('title', 'Payment '.\App\Services\Sales\Allocations::docLabel('', $p->number))
@section('content')
@php use App\Support\Money; use App\Services\Sales\Allocations; $label = Allocations::docLabel('', $p->number); @endphp
@include('purchases._tabs')
<div class="bar">
    <div><h1 style="margin:0">Payment {{ $label }} <span class="pill {{ $p->status === 'void' ? 'overdue' : '' }}">{{ $p->status === 'void' ? 'Void' : 'Paid' }}</span></h1><a class="muted" href="{{ route('vendor-payments.index') }}">&larr; Payments</a></div>
    <div class="actions">
        @include('documents._actions', ['type' => 'remittance', 'id' => $p->id])
        @if ($p->status === 'paid')
            <a class="btn ghost" href="{{ route('vendor-payments.edit', $p->id) }}">Edit</a>
            <form class="inline" method="POST" action="{{ route('vendor-payments.void', $p->id) }}" onsubmit="return confirm('Void this payment? Its journal entry is reversed and the bills it paid become unpaid again.')">@csrf<button class="danger">Void</button></form>
        @else
            <form class="inline" method="POST" action="{{ route('vendor-payments.destroy', $p->id) }}" onsubmit="return confirm('Delete this voided payment for good?')">@csrf @method('DELETE')<button class="danger">Delete</button></form>
        @endif
    </div>
</div>
<div class="card" style="margin-bottom:16px"><dl class="kv">
    <dt>Vendor</dt><dd><a href="{{ route('vendors.show', $p->vendor_id) }}">{{ $p->vendor_name }}</a></dd><dt>Date</dt><dd>{{ Money::date($p->date) }}</dd><dt>Amount</dt><dd><strong>{{ Money::format($p->amount) }}</strong></dd>
    <dt>Paid from</dt><dd>{{ $p->ledger_name }}</dd>
    @if ($p->posted_ledger_name)<dt>Posted to</dt><dd>{{ $p->posted_ledger_name }} <span class="muted">(the vendor's default expense account, not Accounts Payable - so it can't pay a bill)</span></dd>@endif
    @if ($p->method)<dt>Method</dt><dd>{{ $p->method }}</dd>@endif @if ($p->reference)<dt>Reference</dt><dd>{{ $p->reference }}</dd>@endif @if ($p->notes)<dt>Notes</dt><dd>{{ $p->notes }}</dd>@endif
    @if ($p->status === 'paid')<dt>Applied to bills</dt><dd>{{ Money::format($appliedTotal) }}</dd><dt>Unapplied</dt><dd><strong>{{ Money::format($unapplied) }}</strong> <span class="muted">a prepayment to the vendor</span></dd>@endif
</dl></div>
@if ($apps)
    <h2>Applied to</h2>
    <div class="card tbl"><table><thead><tr><th>Bill</th><th>Date</th><th class="num">Bill total</th><th class="num">Applied</th><th></th></tr></thead><tbody>
        @foreach ($apps as $a)
            <tr><td><a href="{{ route('bills.show', $a->doc_id) }}">{{ $a->label }}</a></td><td>{{ Money::date($a->date) }}</td><td class="num">{{ Money::format($a->total) }}</td><td class="num">{{ Money::format($a->amount) }}</td>
                <td style="text-align:right">@if ($p->status === 'paid')<form class="inline" method="POST" action="{{ route('vendor-payments.allocation.remove', $a->id) }}">@csrf<button class="ghost sm">Remove</button></form>@endif</td></tr>
        @endforeach
    </tbody></table></div>
@endif
@if ($p->status === 'paid' && Money::positive($unapplied) && $openDocs)
    <h2>Apply the remaining {{ Money::format($unapplied) }}</h2>
    <form method="POST" action="{{ route('vendor-payments.apply', $p->id) }}" class="card tbl">@csrf
        <table><thead><tr><th>Bill</th><th>Date</th><th>Due</th><th class="num">Balance due</th><th class="num" style="width:130px">Apply</th></tr></thead><tbody>
            @foreach ($openDocs as $d)<tr><td>{{ $d->label }}</td><td>{{ Money::date($d->date) }}</td><td>{{ $d->due_date ? Money::date($d->due_date) : '—' }}</td><td class="num">{{ Money::format($d->balance_due) }}</td><td><input class="num" name="apply[{{ $d->id }}]" inputmode="decimal" placeholder="0.00"></td></tr>@endforeach
        </tbody></table><div class="actions" style="margin-top:12px"><button>Apply</button></div>
    </form>
@endif
@if ($refunds->count())
    <h2>Refunds</h2>
    <div class="card tbl"><table>
        <thead><tr><th>Date</th><th>Paid into</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody>@foreach ($refunds as $r)
            <tr><td>{{ Money::date($r->date) }}</td><td>{{ $r->ledger_name }}</td><td>{{ $r->method }}</td><td>{{ $r->reference }}</td><td class="num">{{ Money::format($r->amount) }}</td>
                <td style="text-align:right"><form class="inline" method="POST" action="{{ route('supplier-credits.refund.delete', $r->id) }}" onsubmit="return confirm('Delete this refund? Its journal entry is reversed.')">@csrf<button class="ghost sm">Delete</button></form></td></tr>
        @endforeach</tbody>
    </table></div>
@endif
@if ($p->status === 'paid' && Money::positive($unapplied))
    @include('credits._refund-form', ['action' => route('vendor-payments.refund', $p->id), 'remaining' => $unapplied, 'heading' => 'Record the unapplied '.Money::format($unapplied).' refunded by the vendor'])
@endif
@include('documents._email-box', ['type' => 'remittance', 'id' => $p->id])
@endsection
