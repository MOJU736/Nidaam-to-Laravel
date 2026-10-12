@extends('layouts.main')
@section('title', 'Invoice '.$sales->label('invoice', $inv->number))
@section('content')
@php
    use App\Support\Money;
    $doc = $inv; $status = $inv->status;
    $badge = ['paid' => ['Paid', ''], 'partial' => ['Partly paid', 'owes'], 'unpaid' => ['Unpaid', 'owes'], 'overdue' => ['Overdue', 'overdue']][$inv->pay_status ?? ''] ?? [ucfirst($status), $status === 'void' ? 'overdue' : ''];
    $owes = $status === 'issued' && Money::positive($inv->balance_due ?? 0);
    $tone = $status === 'void' ? 'void' : ($status === 'issued' && ! $owes ? 'settled' : '');
@endphp
@include('sales._tabs')
<a class="back" href="{{ route('invoices.index') }}">&larr; Invoices</a>
<div class="bar">
    <div class="titlerow"><h1>Invoice {{ $sales->label('invoice', $inv->number) }}</h1><span class="pill {{ $badge[1] }}">{{ $badge[0] }}</span></div>
    <div class="actions">
        @if ($status === 'draft')
            <form class="inline" method="POST" action="{{ route('invoices.issue', $inv->id) }}" @if ($belowCost) onsubmit="return confirm('Some lines are sold below cost. Issue anyway?')" @endif>@csrf<button>Issue invoice</button></form>
            <a class="btn ghost" href="{{ route('invoices.edit', $inv->id) }}">Edit</a>
        @elseif ($owes)
            <a class="btn" href="{{ route('payments.create', ['customer' => $inv->customer_id, 'invoice' => $inv->id, 'amount' => $inv->balance_due]) }}">Receive payment</a>
        @endif
        @include('documents._actions', ['type' => 'invoice', 'id' => $inv->id])
        <details class="menu">
            <summary>More <svg class="ic" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></summary>
            <div class="drop">
                @if ($status === 'issued')
                    @if (Money::positive($credit ?? 0) && Money::positive($inv->balance_due ?? 0))
                        <form method="POST" action="{{ route('invoices.apply-credit', $inv->id) }}">@csrf<button class="ghost">Apply {{ Money::format($credit) }} credit</button></form>
                    @endif
                    <a href="{{ route('credit-notes.create', ['invoice' => $inv->id]) }}">Credit note</a>
                    <a href="{{ route('recurring.create', ['invoice' => $inv->id]) }}">Make recurring</a>
                    @if ($owes)<a href="{{ route('invoices.write-off', $inv->id) }}">Write off</a>@endif
                    <a href="{{ route('invoices.edit', $inv->id) }}">Edit due date / notes</a>
                    <hr>
                    <form method="POST" action="{{ route('invoices.revert', $inv->id) }}" onsubmit="return confirm('Revert to draft? Its journal entry is reversed and any payments applied to it become unapplied credit.')">@csrf<button class="ghost">Revert to draft</button></form>
                    <form method="POST" action="{{ route('invoices.void', $inv->id) }}" onsubmit="return confirm('Void this invoice? Its journal entry is reversed and stock restored.')">@csrf<button class="danger">Void invoice</button></form>
                @elseif ($status === 'draft')
                    <form method="POST" action="{{ route('invoices.destroy', $inv->id) }}" onsubmit="return confirm('Delete this draft invoice?')">@csrf @method('DELETE')<button class="danger">Delete draft</button></form>
                @else
                    <form method="POST" action="{{ route('invoices.destroy', $inv->id) }}" onsubmit="return confirm('Delete this voided invoice?')">@csrf @method('DELETE')<button class="danger">Delete invoice</button></form>
                @endif
            </div>
        </details>
    </div>
</div>

@if ($belowCost)
    <div class="flash bad"><strong>Sold below cost:</strong> {{ implode('; ', $belowCost) }}.</div>
@endif

<div class="docgrid">
    <div class="card docmeta">
        <div class="wide2"><small>Billed to</small><div class="who"><a href="{{ route('customers.show', $inv->customer_id) }}">{{ $inv->customer_name }}</a></div>@if ($inv->customer_address)<div class="muted">{!! nl2br(e($inv->customer_address)) !!}</div>@endif</div>
        <div><small>Date</small>{{ Money::date($inv->date) }}</div>
        @if ($inv->due_date)<div><small>Due</small>{{ Money::date($inv->due_date) }}</div>@endif
        @if ($inv->quotation_id)<div><small>From</small><a href="{{ route('quotations.show', $inv->quotation_id) }}">Quotation</a></div>@endif
        @if ($vehicles)<div><small>Vehicles</small>{{ implode(', ', $vehicles) }}</div>@endif
        @if ($inv->notes)<div class="wide2"><small>Notes</small>{!! nl2br(e($inv->notes)) !!}</div>@endif
    </div>
    <div class="card balance {{ $tone }}">
        @if ($status === 'issued')
            <small>{{ $owes ? 'Balance due' : 'Paid in full' }}</small>
            <div class="big">{{ Money::format($owes ? $inv->balance_due : $inv->total) }}</div>
            <dl><dt>Invoice total</dt><dd>{{ Money::format($inv->total) }}</dd><dt>Paid so far</dt><dd>{{ Money::format($inv->paid) }}</dd></dl>
        @else
            <small>{{ $status === 'void' ? 'Voided' : 'Draft total' }}</small>
            <div class="big">{{ Money::format($inv->total) }}</div>
            <dl><dt>{{ $status === 'void' ? 'No longer counts in the books' : 'Not in the books until issued' }}</dt><dd></dd></dl>
        @endif
    </div>
</div>

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
@include('documents._email-box', ['type' => 'invoice', 'id' => $inv->id])
@endsection
