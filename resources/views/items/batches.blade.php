@extends('layouts.main')
@section('title', 'Batches - '.$item->name)
@section('content')
@php
    use App\Services\Inventory\StockService as S;
    $today = date('Y-m-d'); $soon = date('Y-m-d', strtotime('+30 days'));
    $poolN = S::qcmp($pool, 0) > 0 ? $pool : '0.000';
@endphp
@include('items._tabs')
<a class="back" href="{{ route('items.edit', $item->id) }}">&larr; Item</a>
<div class="bar"><h1>{{ $item->name }} — batches</h1></div>

@unless ($item->track_batches)
    <div class="flash bad">Batch tracking is off for this item. Switch on "Track batches and expiry dates" on the <a href="{{ route('items.edit', $item->id) }}">item</a> so sales take the earliest expiry and expired batches can't be sold.</div>
@endunless

<div class="stats">
    <div class="stat"><b>{{ S::text($item->quantity_on_hand) }}</b><span>on hand</span></div>
    <div class="stat"><b>{{ count($batches) }}</b><span>batch{{ count($batches) === 1 ? '' : 'es' }} with stock</span></div>
    <div class="stat"><b @if ($poolN !== '0.000') style="color:var(--danger)" @endif>{{ S::text($poolN) }}</b><span>with no batch</span></div>
</div>

<div class="row2" style="grid-template-columns:2fr 1fr;align-items:start">
    <div class="card tbl">
        <table>
            <thead><tr><th>Batch</th><th>Expires</th><th class="num">Units</th><th></th></tr></thead>
            <tbody>
            @forelse ($batches as $b)
                <tr>
                    <td colspan="4">
                        <form method="POST" action="{{ route('items.batches.update', $b->id) }}" class="actions" style="align-items:center">
                            @csrf @method('PUT')
                            <input name="batch_no" value="{{ $b->batch_no }}" maxlength="60" style="width:160px">
                            <input type="date" name="expiry_date" value="{{ $b->expiry_date }}" style="width:160px">
                            @if ($b->expiry_date && $b->expiry_date < $today)<span class="pill overdue">Expired</span>@elseif ($b->expiry_date && $b->expiry_date <= $soon)<span class="pill owes">Expires soon</span>@endif
                            <strong style="margin-left:auto">{{ S::text($b->qty) }}</strong>
                            <button class="ghost sm">Save</button>
                            <button class="ghost sm" name="release" value="1" onclick="return confirm('Remove batch {{ addslashes($b->batch_no) }}? Its units stay in stock with no batch.')" title="Remove batch">&times;</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted" style="text-align:center;padding:20px">No batches yet. Give the units on hand a batch number and expiry date on the right.</td></tr>
            @endforelse
            </tbody>
        </table>
        <p class="muted" style="font-size:13px;padding:0 8px">Sales take the batch that expires first. To throw away expired stock, make a stock adjustment — it comes out of expired batches first.</p>
    </div>
    <form class="card" method="POST" action="{{ route('items.batches', $item->id) }}">
        @csrf
        <strong>Give units a batch</strong>
        <label for="batch_no">Batch number</label><input id="batch_no" name="batch_no" maxlength="60" required placeholder="as printed on the box">
        <label for="expiry_date">Expiry date</label><input id="expiry_date" type="date" name="expiry_date">
        <label for="qty">Units</label><input id="qty" name="qty" inputmode="decimal" required placeholder="up to {{ S::text($poolN) }}">
        <p class="muted" style="font-size:13px;margin:4px 0 0">From the {{ S::text($poolN) }} units that have no batch yet.</p>
        <div class="actions" style="margin-top:14px"><button>Save batch</button></div>
    </form>
</div>
@endsection
