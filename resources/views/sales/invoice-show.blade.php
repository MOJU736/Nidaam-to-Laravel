@extends('layouts.main')
@section('title', 'Invoice '.$sales->label('invoice', $inv->number))
@section('content')
@php use App\Support\Money; $doc = $inv; $status = $inv->status; @endphp
@include('sales._tabs')
<div class="bar">
    <div>
        <h1 style="margin:0">Invoice {{ $sales->label('invoice', $inv->number) }}
            @php $badge = ['paid' => ['Paid', ''], 'partial' => ['Partly paid', 'owes'], 'unpaid' => ['Unpaid', 'owes'], 'overdue' => ['Overdue', 'overdue']][$inv->pay_status ?? ''] ?? [ucfirst($status), $status === 'void' ? 'overdue' : '']; @endphp
            <span class="pill {{ $badge[1] }}">{{ $badge[0] }}</span></h1>
        <a class="muted" href="{{ route('invoices.index') }}">&larr; Invoices</a>
    </div>
    <div class="actions">
        @if ($status === 'draft')
            <form class="inline" method="POST" action="{{ route('invoices.issue', $inv->id) }}" @if ($belowCost) onsubmit="return confirm('Some lines are sold below cost. Issue anyway?')" @endif>@csrf<button>Issue</button></form>
            <a class="btn ghost" href="{{ route('invoices.edit', $inv->id) }}">Edit</a>
            <form class="inline" method="POST" action="{{ route('invoices.destroy', $inv->id) }}" onsubmit="return confirm('Delete this draft invoice?')">@csrf @method('DELETE')<button class="danger">Delete</button></form>
        @elseif ($status === 'issued')
            @if (Money::positive($inv->balance_due ?? 0))<a class="btn" href="{{ route('payments.create', ['customer' => $inv->customer_id, 'invoice' => $inv->id, 'amount' => $inv->balance_due]) }}">Receive payment</a>@endif
            @if (Money::positive($credit ?? 0) && Money::positive($inv->balance_due ?? 0))
                <form class="inline" method="POST" action="{{ route('invoices.apply-credit', $inv->id) }}">@csrf<button class="ghost">Apply {{ Money::format($credit) }} credit</button></form>
            @endif
            <a class="btn ghost" href="{{ route('credit-notes.create', ['invoice' => $inv->id]) }}">Credit note</a>
            @if (Money::positive($inv->balance_due ?? 0))<a class="btn ghost" href="{{ route('invoices.write-off', $inv->id) }}">Write off</a>@endif
            <a class="btn ghost" href="{{ route('invoices.edit', $inv->id) }}">Edit due date / notes</a>
            <form class="inline" method="POST" action="{{ route('invoices.revert', $inv->id) }}" onsubmit="return confirm('Revert to draft? Its journal entry is reversed and any payments applied to it become unapplied credit.')">@csrf<button class="ghost">Revert to draft</button></form>
            <form class="inline" method="POST" action="{{ route('invoices.void', $inv->id) }}" onsubmit="return confirm('Void this invoice? Its journal entry is reversed and stock restored.')">@csrf<button class="danger">Void</button></form>
        @else
            <form class="inline" method="POST" action="{{ route('invoices.destroy', $inv->id) }}" onsubmit="return confirm('Delete this voided invoice?')">@csrf @method('DELETE')<button class="danger">Delete</button></form>
        @endif
    </div>
</div>

@if ($belowCost)
    <div class="flash bad"><strong>Sold below cost:</strong> {{ implode('; ', $belowCost) }}.</div>
@endif

<div class="card" style="margin-bottom:16px"><dl class="kv">
    <dt>Customer</dt><dd><a href="{{ route('customers.show', $inv->customer_id) }}">{{ $inv->customer_name }}</a>@if ($inv->customer_address)<div class="muted">{!! nl2br(e($inv->customer_address)) !!}</div>@endif</dd>
    <dt>Date</dt><dd>{{ Money::date($inv->date) }}</dd>
    @if ($inv->due_date)<dt>Due</dt><dd>{{ Money::date($inv->due_date) }}</dd>@endif
    @if ($inv->quotation_id)<dt>From</dt><dd><a href="{{ route('quotations.show', $inv->quotation_id) }}">Quotation</a></dd>@endif
    @if ($vehicles)<dt>Vehicles</dt><dd>{{ implode(', ', $vehicles) }}</dd>@endif
    @if ($inv->notes)<dt>Notes</dt><dd>{!! nl2br(e($inv->notes)) !!}</dd>@endif
    @if ($status === 'issued')<dt>Paid</dt><dd>{{ Money::format($inv->paid) }} <span class="muted">of {{ Money::format($inv->total) }}</span></dd><dt>Balance due</dt><dd><strong>{{ Money::format($inv->balance_due) }}</strong></dd>@endif
</dl></div>

@include('sales._doc-lines')

@if (! empty($applied))
    <h2>Payments applied</h2>
    <div class="card tbl"><table>
        <thead><tr><th>Payment</th><th>Date</th><th class="num">Applied</th><th></th></tr></thead>
        <tbody>
        @foreach ($applied as $a)
            <tr><td>@if ($a->pay_type === 'pay')<a href="{{ route('payments.show', $a->payment_id) }}">{{ $a->label }}</a>@else{{ $a->label }}@endif</td><td>{{ Money::date($a->date) }}</td><td class="num">{{ Money::format($a->amount) }}</td>
                <td style="text-align:right">@if ($a->source !== 'writeoff')<form class="inline" method="POST" action="{{ route('payments.allocation.remove', $a->id) }}">@csrf<input type="hidden" name="return" value="invoice:{{ $inv->id }}"><button class="ghost sm">Remove</button></form>@endif</td></tr>
        @endforeach
        </tbody>
    </table></div>
@endif
@endsection
