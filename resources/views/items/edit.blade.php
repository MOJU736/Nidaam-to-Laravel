@extends('layouts.main')
@section('title', 'Edit item')
@section('content')
@php
    use App\Services\Inventory\ItemService;
    use App\Services\Inventory\StockService;
    use App\Support\Money;
    $v = fn ($f, $d = '') => old($f, $item->{$f} ?? $d);
@endphp
@include('items._tabs')
<a class="back" href="{{ route('items.index') }}">&larr; Items</a>
<div class="bar"><h1>Edit item</h1></div>

<form class="card" method="POST" action="{{ route('items.update', $item->id) }}" style="max-width:760px">
    @csrf @method('PUT')
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <div class="row2">
        <div><label for="name" style="margin-top:0">Item name *</label><input id="name" name="name" value="{{ $v('name') }}" maxlength="255" required></div>
        <div><label for="type" style="margin-top:0">Type *</label><select id="type" name="type">@foreach (ItemService::TYPES as $k => $l)<option value="{{ $k }}" @selected($v('type') === $k)>{{ $l }}</option>@endforeach</select></div>
    </div>
    <div class="row2">
        <div><label for="sku">SKU</label><input id="sku" name="sku" value="{{ $v('sku') }}" maxlength="100"></div>
        <div><label for="category_id">Category</label><select id="category_id" name="category_id"><option value="">—</option>@foreach ($categories as $id => $n)<option value="{{ $id }}" @selected((string) $v('category_id') === (string) $id)>{{ $n }}</option>@endforeach</select></div>
    </div>
    <label for="description">Description</label><input id="description" name="description" value="{{ $v('description') }}" maxlength="500">
    <div class="row2">
        <div><label for="sales_price">Sales price</label><input id="sales_price" name="sales_price" inputmode="decimal" value="{{ $v('sales_price', '0.00') }}"></div>
        <div></div>
    </div>
    <div class="row2">
        <div><label for="sales_ledger_id">Income account *</label><select id="sales_ledger_id" name="sales_ledger_id" required><option value="">Choose...</option>@foreach ($income as $id => $n)<option value="{{ $id }}" @selected((string) $v('sales_ledger_id') === (string) $id)>{{ $n }}</option>@endforeach</select></div>
        <div><label for="expense_ledger_id">Expense account</label><select id="expense_ledger_id" name="expense_ledger_id"><option value="">None</option>@foreach ($expense as $id => $n)<option value="{{ $id }}" @selected((string) $v('expense_ledger_id') === (string) $id)>{{ $n }}</option>@endforeach</select></div>
    </div>

    @if ($item->type === 'inventory')
        <h2>Stock</h2>
        <dl class="kv"><dt>In stock</dt><dd><strong>{{ StockService::text($item->quantity_on_hand) }}</strong> <span class="muted">— kept up to date by bills, invoices and stock adjustments</span></dd>
            <dt>Average cost</dt><dd>{{ Money::format($item->purchase_cost) }}</dd></dl>
        @if ($batchCols)
            <div class="row2" style="margin-top:8px">
                <div><label for="reorder_level">Reorder level</label><input id="reorder_level" name="reorder_level" inputmode="decimal" value="{{ old('reorder_level', $item->reorder_level) }}" placeholder="Warn when stock falls to this"></div>
                <div><label style="margin-top:28px;font-weight:400"><input type="checkbox" name="track_batches" value="1" @checked(old('track_batches', $item->track_batches ?? 0))> Track batches and expiry dates</label>
                    @if ($item->track_batches ?? 0)<a href="{{ route('items.batches', $item->id) }}">Manage batches</a>@endif</div>
            </div>
        @endif
    @endif

    <label style="margin-top:16px;font-weight:400"><input type="checkbox" name="status" value="1" @checked(old('status', $item->status))> Active</label>
    <div class="actions" style="margin-top:20px"><button>Save changes</button><a class="btn ghost" href="{{ route('items.index') }}">Cancel</a></div>
</form>
@endsection
