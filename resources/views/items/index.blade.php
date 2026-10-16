@extends('layouts.main')
@section('title', 'Items')
@section('content')
@php
    use App\Services\Inventory\ItemService;
    use App\Services\Inventory\StockService;
    use App\Support\Money;
    use App\Support\Icon;
    $types = ItemService::TYPES;
    $nInv = 0; $nLow = 0; $nOff = 0; $value = '0.00';
    foreach ($items as $i) {
        if (! $i->status) { $nOff++; }
        if ($i->type === 'inventory') {
            $nInv++;
            $value = Money::add($value, bcmul((string) $i->quantity_on_hand, (string) $i->purchase_cost, 2));
            if (isset($i->reorder_level) && $i->reorder_level !== null && (float) $i->quantity_on_hand <= (float) $i->reorder_level) { $nLow++; }
        }
    }
@endphp
@include('items._tabs')
<div class="bar">
    <div><h1>Items</h1><div class="muted">Products and services you buy and sell</div></div>
    <a class="btn" href="{{ route('items.create') }}">{{ Icon::svg('plus', 16) }}Add items</a>
</div>

<div class="tiles">
    <div class="tile"><small>Items</small><b>{{ count($items) }}</b><span>{{ $nInv }} stocked{{ $nOff ? ', '.$nOff.' inactive' : '' }}</span></div>
    <div class="tile"><small>Stock value</small><b>{{ Money::format($value) }}</b><span>at average cost</span></div>
    <div class="tile {{ $nLow ? 'bad' : '' }}"><small>Running low</small><b>{{ $nLow }}</b><span>at or below the reorder level</span></div>
</div>

<div class="tools">
    <div class="chips" id="chips">
        <button type="button" class="on" data-f="">All</button>
        <button type="button" data-f="inventory">Stocked</button>
        <button type="button" data-f="service">Services</button>
        <button type="button" data-f="low">Running low</button>
        <button type="button" data-f="off">Inactive</button>
    </div>
    <div class="actions">
        <form method="GET" class="inline"><select name="category_id" onchange="this.form.submit()" style="width:auto"><option value="">All categories</option>@foreach ($categories as $id => $name)<option value="{{ $id }}" @selected($selected === $id)>{{ $name }}</option>@endforeach</select></form>
        <input id="q" class="find" type="search" placeholder="Search name, code or category">
    </div>
</div>

<div class="card tbl">
    <table id="list">
        <thead><tr><th>Name</th><th>Type</th><th>Category</th><th class="num">Sales price</th><th class="num">Avg cost</th><th class="num">In stock</th><th></th></tr></thead>
        <tbody>
        @forelse ($items as $i)
            @php $low = $i->type === 'inventory' && isset($i->reorder_level) && $i->reorder_level !== null && (float) $i->quantity_on_hand <= (float) $i->reorder_level; @endphp
            <tr data-tags="{{ $i->type }}{{ $low ? ' low' : '' }}{{ $i->status ? '' : ' off' }}" data-text="{{ strtolower($i->name.' '.$i->sku.' '.$i->category_name) }}" @style(['opacity:.6' => ! $i->status])>
                <td><a class="who" href="{{ route('items.edit', $i->id) }}"><span class="avs sq">{{ mb_strtoupper(mb_substr(trim($i->name), 0, 1)) }}</span><span><strong>{{ $i->name }}</strong>@if ($i->sku)<small class="muted" style="display:block;font-size:12.5px">{{ $i->sku }}</small>@endif</span></a></td>
                <td>{{ $types[$i->type] ?? $i->type }}@unless ($i->status) <span class="pill">Inactive</span>@endunless</td>
                <td>{{ $i->category_name ?: '—' }}</td>
                <td class="num">{{ Money::format($i->sales_price) }}</td>
                <td class="num">{{ $i->type === 'inventory' ? Money::format($i->purchase_cost) : '—' }}</td>
                <td class="num">
                    @if ($i->type === 'inventory')
                        {{ StockService::text($i->quantity_on_hand) }}
                        @if ($low)<span class="pill overdue" title="At or below the reorder level">Reorder</span>@endif
                    @else — @endif
                </td>
                <td>
                    <div class="actions" style="justify-content:flex-end;flex-wrap:nowrap">
                        <a class="btn ghost sm" href="{{ route('items.edit', $i->id) }}">Edit</a>
                        @if ($i->type === 'inventory' && ($i->track_batches ?? 0))<a class="btn ghost sm" href="{{ route('items.batches', $i->id) }}">Batches</a>@endif
                        <form class="inline" method="POST" action="{{ route('items.destroy', $i->id) }}" onsubmit="return confirm('Delete this item?')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted" style="text-align:center;padding:36px">No items yet. <a href="{{ route('items.create') }}">Add your first items</a>.</td></tr>
        @endforelse
        <tr id="none" hidden><td colspan="7" class="muted" style="text-align:center;padding:28px">Nothing matches.</td></tr>
        </tbody>
    </table>
</div>
<script>
(function () {
    var q = document.getElementById('q'), rows = [].slice.call(document.querySelectorAll('#list tbody tr[data-text]')), chips = [].slice.call(document.querySelectorAll('#chips button')), f = '';
    function apply() {
        var t = q.value.trim().toLowerCase(), shown = 0;
        rows.forEach(function (r) { var ok = (!f || r.dataset.tags.split(' ').indexOf(f) > -1) && (!t || r.dataset.text.indexOf(t) > -1); r.hidden = !ok; if (ok) { shown++; } });
        document.getElementById('none').hidden = shown > 0 || !rows.length;
    }
    q.addEventListener('input', apply);
    chips.forEach(function (c) { c.addEventListener('click', function () { chips.forEach(function (x) { x.classList.remove('on'); }); c.classList.add('on'); f = c.dataset.f; apply(); }); });
})();
</script>
@endsection
