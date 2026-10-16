@extends('layouts.main')
@section('title', $ui['name'].' '.$cn->label)
@section('content')
@php use App\Support\Money; $wo = $cn->kind === 'writeoff'; $word = $wo ? 'Write-off' : $ui['name']; @endphp
@include('credits._tabs')
<div class="bar">
    <div><h1>{{ $word }} {{ $cn->label }} <span class="pill {{ $cn->status === 'void' ? 'overdue' : '' }}">{{ ucfirst($cn->status) }}</span></h1><a class="muted" href="{{ route($ui['route'].'.index') }}">&larr; {{ $ui['names'] }}</a></div>
    <div class="actions">
        @include('documents._actions', ['type' => $ui['side'] === 'ar' ? 'credit-note' : 'supplier-credit', 'id' => $cn->id])
        @if ($cn->status === 'draft')
            <form class="inline" method="POST" action="{{ route($ui['route'].'.issue', $cn->id) }}">@csrf<button>Issue</button></form>
            <a class="btn ghost" href="{{ route($ui['route'].'.edit', $cn->id) }}">Edit</a>
            <form class="inline" method="POST" action="{{ route($ui['route'].'.destroy', $cn->id) }}" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')<button class="danger">Delete</button></form>
        @elseif ($cn->status === 'issued')
            <form class="inline" method="POST" action="{{ route($ui['route'].'.void', $cn->id) }}" onsubmit="return confirm('Void this? Its journal entry is reversed, stock it moved goes back, and anything it paid becomes unpaid again.')">@csrf<button class="danger">Void</button></form>
        @else
            <form class="inline" method="POST" action="{{ route($ui['route'].'.destroy', $cn->id) }}" onsubmit="return confirm('Delete this voided one for good?')">@csrf @method('DELETE')<button class="danger">Delete</button></form>
        @endif
    </div>
</div>

<div class="card" style="margin-bottom:16px"><dl class="kv">
    <dt>{{ ucfirst($ui['party_word']) }}</dt><dd><a href="{{ route($ui['party_route'], $cn->party_id) }}">{{ $cn->party_name }}</a></dd>
    <dt>Date</dt><dd>{{ Money::date($cn->date) }}</dd>
    @if ($cn->source_label)<dt>For</dt><dd><a href="{{ $cn->source_url }}">{{ $cn->source_label }}</a></dd>@endif
    @if ($cn->notes)<dt>Notes</dt><dd>{!! nl2br(e($cn->notes)) !!}</dd>@endif
    @if ($cn->status === 'issued')<dt>Left to use</dt><dd><strong>{{ Money::format($remaining) }}</strong> <span class="muted">of {{ Money::format($cn->total) }}</span></dd>@endif
</dl></div>

<div class="card tbl" style="margin-bottom:16px"><table>
    <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th>@if (! $wo)<th>{{ $ui['side'] === 'ar' ? 'Back to stock' : 'Returned' }}</th>@endif</tr></thead>
    <tbody>
    @foreach ($cn->items as $l)
        <tr><td>{{ $l->description }}@if ($l->item_name)<div class="muted" style="font-size:13px">{{ $l->item_name }}</div>@endif</td><td class="num">{{ rtrim(rtrim(number_format((float) $l->qty, 2, '.', ','), '0'), '.') }}</td>
            <td class="num">{{ Money::format($l->unit_price) }}</td><td class="num">{{ Money::format($l->amount) }}</td>@if (! $wo)<td>{{ $l->restock ? 'Yes' : '' }}</td>@endif</tr>
    @endforeach
    <tr><td colspan="3" class="num">Subtotal</td><td class="num">{{ Money::format($cn->subtotal) }}</td>@if (! $wo)<td></td>@endif</tr>
    @if (Money::positive($cn->tax_total))<tr><td colspan="3" class="num">Tax @if ($cn->tax_percent !== null)({{ rtrim(rtrim((string) $cn->tax_percent, '0'), '.') }}%)@endif</td><td class="num">{{ Money::format($cn->tax_total) }}</td>@if (! $wo)<td></td>@endif</tr>@endif
    <tr><td colspan="3" class="num"><strong>Total</strong></td><td class="num"><strong>{{ Money::format($cn->total) }}</strong></td>@if (! $wo)<td></td>@endif</tr>
    </tbody>
</table></div>

@if ($apps)
    <h2>Applied to</h2>
    <div class="card tbl"><table>
        <thead><tr><th>{{ ucfirst($ui['doc_word']) }}</th><th>Date</th><th class="num">Total</th><th class="num">Applied</th><th></th></tr></thead>
        <tbody>@foreach ($apps as $a)
            <tr><td><a href="{{ route($ui['doc_route'], $a->doc_id) }}">{{ $a->label }}</a></td><td>{{ Money::date($a->date) }}</td><td class="num">{{ Money::format($a->total) }}</td><td class="num">{{ Money::format($a->amount) }}</td>
                <td style="text-align:right">@if ($cn->status === 'issued' && $a->source !== 'writeoff')<form class="inline" method="POST" action="{{ route($ui['route'].'.allocation.remove', $a->id) }}">@csrf<button class="ghost sm">Remove</button></form>@endif</td></tr>
        @endforeach</tbody>
    </table></div>
@endif

@if ($canRefund && $openDocs)
    <h2>Apply the remaining {{ Money::format($remaining) }}</h2>
    <form method="POST" action="{{ route($ui['route'].'.apply', $cn->id) }}" class="card tbl">
        @csrf
        <table><thead><tr><th>{{ ucfirst($ui['doc_word']) }}</th><th>Date</th><th>Due</th><th class="num">Balance due</th><th class="num" style="width:130px">Apply</th></tr></thead>
            <tbody>@foreach ($openDocs as $d)<tr><td>{{ $d->label }}</td><td>{{ Money::date($d->date) }}</td><td>{{ $d->due_date ? Money::date($d->due_date) : '—' }}</td><td class="num">{{ Money::format($d->balance_due) }}</td>
                <td><input class="num" name="apply[{{ $d->id }}]" inputmode="decimal" placeholder="0.00"></td></tr>@endforeach</tbody></table>
        <div class="actions" style="margin-top:12px"><button>Apply</button></div>
    </form>
@endif

@if ($refunds->count())
    <h2>Refunds</h2>
    <div class="card tbl"><table>
        <thead><tr><th>Date</th><th>{{ $ui['side'] === 'ar' ? 'Paid from' : 'Paid into' }}</th><th>Method</th><th>Reference</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody>@foreach ($refunds as $r)
            <tr><td>{{ Money::date($r->date) }}</td><td>{{ $r->ledger_name }}</td><td>{{ $r->method }}</td><td>{{ $r->reference }}</td><td class="num">{{ Money::format($r->amount) }}</td>
                <td style="text-align:right"><form class="inline" method="POST" action="{{ route($ui['route'].'.refund.delete', $r->id) }}" onsubmit="return confirm('Delete this refund? Its journal entry is reversed.')">@csrf<button class="ghost sm">Delete</button></form></td></tr>
        @endforeach</tbody>
    </table></div>
@endif

@if ($canRefund)
    @include('credits._refund-form', ['action' => route($ui['route'].'.refund', $cn->id), 'remaining' => $remaining, 'heading' => $ui['side'] === 'ar' ? 'Refund the customer in cash' : 'Record cash received from the supplier'])
@endif
@include('documents._email-box', ['type' => $ui['side'] === 'ar' ? 'credit-note' : 'supplier-credit', 'id' => $cn->id])
@endsection
