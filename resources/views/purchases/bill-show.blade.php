@extends('layouts.main')
@section('title', 'Bill '.$purchases->billLabel($b->number))
@section('content')
@php use App\Support\Money; $doc = $b; $status = $b->status; @endphp
@include('purchases._tabs')
@php
    $badge = ['paid' => ['Paid', ''], 'partial' => ['Partly paid', 'owes'], 'unpaid' => ['Unpaid', 'owes'], 'overdue' => ['Overdue', 'overdue']][$b->pay_status ?? ''] ?? [ucfirst($status), $status === 'void' ? 'overdue' : ''];
    $owes = $status === 'issued' && Money::positive($b->balance_due ?? 0);
    $tone = $status === 'void' ? 'void' : ($status === 'issued' && ! $owes ? 'settled' : '');
@endphp
<a class="back" href="{{ route('bills.index') }}">&larr; Bills</a>
<div class="bar">
    <div class="titlerow"><h1>Bill {{ $purchases->billLabel($b->number) }}</h1><span class="pill {{ $badge[1] }}">{{ $badge[0] }}</span></div>
    <div class="actions">
        @if ($status === 'draft')
            <form class="inline" method="POST" action="{{ route('bills.issue', $b->id) }}">@csrf<button>Issue bill</button></form>
            <a class="btn ghost" href="{{ route('bills.edit', $b->id) }}">Edit</a>
        @elseif ($owes)
            <a class="btn" href="{{ route('vendor-payments.create', ['vendor' => $b->vendor_id, 'bill' => $b->id, 'amount' => $b->balance_due]) }}">Pay vendor</a>
        @endif
        @include('documents._actions', ['type' => 'bill', 'id' => $b->id])
        <details class="menu">
            <summary>More <svg class="ic" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></summary>
            <div class="drop">
                @if ($status === 'issued')
                    @if (Money::positive($credit ?? 0) && $owes)<form method="POST" action="{{ route('bills.apply-credit', $b->id) }}">@csrf<button class="ghost">Apply {{ Money::format($credit) }} credit</button></form>@endif
                    <a href="{{ route('supplier-credits.create', ['bill' => $b->id]) }}">Supplier credit</a><hr>
                    <form method="POST" action="{{ route('bills.revert', $b->id) }}" onsubmit="return confirm('Revert to draft? Its journal entry is reversed, stock it bought is taken back out, and payments applied to it become prepayments.')">@csrf<button class="ghost">Revert to draft</button></form>
                    <form method="POST" action="{{ route('bills.void', $b->id) }}" onsubmit="return confirm('Void this bill? Its journal entry is reversed.')">@csrf<button class="danger">Void bill</button></form>
                @elseif ($status === 'draft')
                    <form method="POST" action="{{ route('bills.destroy', $b->id) }}" onsubmit="return confirm('Delete this draft bill?')">@csrf @method('DELETE')<button class="danger">Delete draft</button></form>
                @else
                    <form method="POST" action="{{ route('bills.destroy', $b->id) }}" onsubmit="return confirm('Delete this voided bill?')">@csrf @method('DELETE')<button class="danger">Delete bill</button></form>
                @endif
            </div>
        </details>
    </div>
</div>
<div class="docgrid">
    <div class="card docmeta">
        <div class="wide2"><small>From</small><div class="who"><a href="{{ route('vendors.show', $b->vendor_id) }}">{{ $b->vendor_name }}</a></div></div>
        <div><small>Date</small>{{ Money::date($b->date) }}</div>
        @if ($b->due_date)<div><small>Due</small>{{ Money::date($b->due_date) }}</div>@endif
        @if ($b->vendor_ref)<div><small>Their reference</small>{{ $b->vendor_ref }}</div>@endif
        @if ($b->purchase_order_id)<div><small>From</small><a href="{{ route('purchase-orders.show', $b->purchase_order_id) }}">Purchase order</a></div>@endif
        @if ($b->notes)<div class="wide2"><small>Notes</small>{!! nl2br(e($b->notes)) !!}</div>@endif
    </div>
    <div class="card balance {{ $tone }}">
        @if ($status === 'issued')
            <small>{{ $owes ? 'Balance due' : 'Paid in full' }}</small>
            <div class="big">{{ Money::format($owes ? $b->balance_due : $b->total) }}</div>
            <dl><dt>Bill total</dt><dd>{{ Money::format($b->total) }}</dd><dt>Paid so far</dt><dd>{{ Money::format($b->paid) }}</dd></dl>
        @else
            <small>{{ $status === 'void' ? 'Voided' : 'Draft total' }}</small>
            <div class="big">{{ Money::format($b->total) }}</div>
        @endif
    </div>
</div>
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
@include('documents._email-box', ['type' => 'bill', 'id' => $b->id])
@endsection
