@extends('layouts.main')
@section('title', 'Bill '.$purchases->billLabel($b->number))
@section('content')
@php use App\Support\Money; $doc = $b; $status = $b->status; @endphp
@include('purchases._tabs')
<div class="bar">
    <div>
        @php $badge = ['paid' => ['Paid', ''], 'partial' => ['Partly paid', 'owes'], 'unpaid' => ['Unpaid', 'owes'], 'overdue' => ['Overdue', 'overdue']][$b->pay_status ?? ''] ?? [ucfirst($status), $status === 'void' ? 'overdue' : '']; @endphp
        <h1 style="margin:0">Bill {{ $purchases->billLabel($b->number) }} <span class="pill {{ $badge[1] }}">{{ $badge[0] }}</span></h1><a class="muted" href="{{ route('bills.index') }}">&larr; Bills</a>
    </div>
    <div class="actions">
        @if ($status === 'draft')
            <form class="inline" method="POST" action="{{ route('bills.issue', $b->id) }}">@csrf<button>Issue</button></form>
            <a class="btn ghost" href="{{ route('bills.edit', $b->id) }}">Edit</a>
            <form class="inline" method="POST" action="{{ route('bills.destroy', $b->id) }}" onsubmit="return confirm('Delete this draft bill?')">@csrf @method('DELETE')<button class="danger">Delete</button></form>
        @elseif ($status === 'issued')
            @if (Money::positive($b->balance_due ?? 0))<a class="btn" href="{{ route('vendor-payments.create', ['vendor' => $b->vendor_id, 'bill' => $b->id, 'amount' => $b->balance_due]) }}">Pay vendor</a>@endif
            @if (Money::positive($credit ?? 0) && Money::positive($b->balance_due ?? 0))<form class="inline" method="POST" action="{{ route('bills.apply-credit', $b->id) }}">@csrf<button class="ghost">Apply {{ Money::format($credit) }} credit</button></form>@endif
            <a class="btn ghost" href="{{ route('supplier-credits.create', ['bill' => $b->id]) }}">Supplier credit</a>
            <form class="inline" method="POST" action="{{ route('bills.revert', $b->id) }}" onsubmit="return confirm('Revert to draft? Its journal entry is reversed, stock it bought is taken back out, and payments applied to it become prepayments.')">@csrf<button class="ghost">Revert to draft</button></form>
            <form class="inline" method="POST" action="{{ route('bills.void', $b->id) }}" onsubmit="return confirm('Void this bill? Its journal entry is reversed.')">@csrf<button class="danger">Void</button></form>
        @else
            <form class="inline" method="POST" action="{{ route('bills.destroy', $b->id) }}" onsubmit="return confirm('Delete this voided bill?')">@csrf @method('DELETE')<button class="danger">Delete</button></form>
        @endif
    </div>
</div>
<div class="card" style="margin-bottom:16px"><dl class="kv">
    <dt>Vendor</dt><dd><a href="{{ route('vendors.show', $b->vendor_id) }}">{{ $b->vendor_name }}</a></dd>
    @if ($b->vendor_ref)<dt>Reference</dt><dd>{{ $b->vendor_ref }}</dd>@endif
    <dt>Date</dt><dd>{{ Money::date($b->date) }}</dd>@if ($b->due_date)<dt>Due</dt><dd>{{ Money::date($b->due_date) }}</dd>@endif
    @if ($b->purchase_order_id)<dt>From</dt><dd><a href="{{ route('purchase-orders.show', $b->purchase_order_id) }}">Purchase order</a></dd>@endif
    @if ($b->notes)<dt>Notes</dt><dd>{!! nl2br(e($b->notes)) !!}</dd>@endif
    @if ($status === 'issued')<dt>Paid</dt><dd>{{ Money::format($b->paid) }} <span class="muted">of {{ Money::format($b->total) }}</span></dd><dt>Balance due</dt><dd><strong>{{ Money::format($b->balance_due) }}</strong></dd>@endif
</dl></div>
@include('purchases._doc-lines')
@if (! empty($applied))
    <h2>Payments applied</h2>
    <div class="card tbl"><table><thead><tr><th>Payment</th><th>Date</th><th class="num">Applied</th><th></th></tr></thead><tbody>
        @foreach ($applied as $a)
            <tr><td>@if ($a->pay_type === 'pay')<a href="{{ route('vendor-payments.show', $a->payment_id) }}">{{ $a->label }}</a>@else{{ $a->label }}@endif</td><td>{{ Money::date($a->date) }}</td><td class="num">{{ Money::format($a->amount) }}</td>
                <td style="text-align:right"><form class="inline" method="POST" action="{{ route('vendor-payments.allocation.remove', $a->id) }}">@csrf<input type="hidden" name="return" value="bill:{{ $b->id }}"><button class="ghost sm">Remove</button></form></td></tr>
        @endforeach
    </tbody></table></div>
@endif
@endsection
