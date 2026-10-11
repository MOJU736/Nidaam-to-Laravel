@extends('layouts.main')
@section('title', 'Items')
@section('content')
@php
    use App\Services\Inventory\ItemService;
    use App\Services\Inventory\StockService;
    use App\Support\Money;
    $types = ItemService::TYPES;
@endphp
@include('items._tabs')
<div class="bar">
    <h1 style="margin:0">Items</h1>
    <div class="actions">
        <input id="q" placeholder="Search items..." style="width:220px">
        <form method="GET" class="inline"><select name="category_id" onchange="this.form.submit()" style="width:auto"><option value="">All categories</option>@foreach ($categories as $id => $name)<option value="{{ $id }}" @selected($selected === $id)>{{ $name }}</option>@endforeach</select></form>
        <a class="btn" href="{{ route('items.create') }}">+ Add items</a>
    </div>
</div>
<div class="card tbl">
    <table id="list">
        <thead><tr><th>Name</th><th>Type</th><th>Category</th><th class="num">Sales price</th><th class="num">Avg cost</th><th class="num">In stock</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($items as $i)
            <tr data-text="{{ strtolower($i->name.' '.$i->sku.' '.$i->category_name) }}">
                <td><a href="{{ route('items.edit', $i->id) }}"><strong>{{ $i->name }}</strong></a>@if ($i->sku)<div class="muted" style="font-size:13px">{{ $i->sku }}</div>@endif</td>
                <td>{{ $types[$i->type] ?? $i->type }}</td>
                <td>{{ $i->category_name ?: '—' }}</td>
                <td class="num">{{ Money::format($i->sales_price) }}</td>
                <td class="num">{{ $i->type === 'inventory' ? Money::format($i->purchase_cost) : '—' }}</td>
                <td class="num">
                    @if ($i->type === 'inventory')
                        {{ StockService::text($i->quantity_on_hand) }}
                        @if (isset($i->reorder_level) && $i->reorder_level !== null && (float) $i->quantity_on_hand <= (float) $i->reorder_level)<span class="pill overdue" title="At or below the reorder level">Reorder</span>@endif
                    @else — @endif
                </td>
                <td>@if ($i->status)<span class="pill">Active</span>@else<span class="pill">Inactive</span>@endif</td>
                <td>
                    <div class="actions" style="justify-content:flex-end">
                        <a class="btn ghost sm" href="{{ route('items.edit', $i->id) }}">Edit</a>
                        @if ($i->type === 'inventory' && ($i->track_batches ?? 0))<a class="btn ghost sm" href="{{ route('items.batches', $i->id) }}">Batches</a>@endif
                        <form class="inline" method="POST" action="{{ route('items.destroy', $i->id) }}" onsubmit="return confirm('Delete this item?')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted" style="text-align:center;padding:24px">No items yet. <a href="{{ route('items.create') }}">Add your first items</a>.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<script>
(function () {
    var q = document.getElementById('q'), rows = [].slice.call(document.querySelectorAll('#list tbody tr[data-text]'));
    q.addEventListener('input', function () { var t = q.value.trim().toLowerCase(); rows.forEach(function (r) { r.hidden = t && r.dataset.text.indexOf(t) === -1; }); });
})();
</script>
@endsection
