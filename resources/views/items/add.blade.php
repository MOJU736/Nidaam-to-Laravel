@extends('layouts.main')
@section('title', 'Add items')
@section('content')
@include('items._tabs')
<a class="back" href="{{ route('items.index') }}">&larr; Items</a>
<div class="bar"><h1>Add items</h1></div>

<form class="card" method="POST" action="{{ route('items.store') }}">
    @csrf
    @foreach ((array) $errors->get('rows') as $e)<p class="error">{{ $e }}</p>@endforeach
    <p class="muted" style="margin-top:0">Register several items at once. A row left blank is ignored. Quantity and cost only apply to Inventory items.</p>
    <div class="tbl">
    <table id="rows">
        <thead><tr><th style="min-width:130px">Type</th><th style="min-width:170px">Name *</th><th>SKU</th><th>Category</th><th class="num">Sales price</th><th style="min-width:190px">Income account *</th><th style="min-width:190px">Expense account</th><th class="num">Cost</th><th class="num">Qty</th><th></th></tr></thead>
        <tbody>
        @foreach ($rows as $i => $r)
            @php $r = array_merge(['type' => 'service', 'category_id' => '', 'sku' => '', 'name' => '', 'description' => '', 'sales_price' => '0.00', 'sales_ledger_id' => '', 'expense_ledger_id' => '', 'purchase_cost' => '0.00', 'quantity_on_hand' => '0.00'], old("rows.$i", $r)); @endphp
            <tr class="r">
                <td><select name="rows[{{ $i }}][type]" class="type">@foreach ($types as $k => $label)<option value="{{ $k }}" @selected($r['type'] === $k)>{{ $label }}</option>@endforeach</select></td>
                <td><input name="rows[{{ $i }}][name]" value="{{ $r['name'] }}" maxlength="255"><input name="rows[{{ $i }}][description]" value="{{ $r['description'] }}" maxlength="500" placeholder="Description" style="margin-top:4px"></td>
                <td><input name="rows[{{ $i }}][sku]" value="{{ $r['sku'] }}" maxlength="100"></td>
                <td><select name="rows[{{ $i }}][category_id]"><option value="">—</option>@foreach ($categories as $id => $n)<option value="{{ $id }}" @selected((string) $r['category_id'] === (string) $id)>{{ $n }}</option>@endforeach</select></td>
                <td><input name="rows[{{ $i }}][sales_price]" class="num" inputmode="decimal" value="{{ $r['sales_price'] }}"></td>
                <td><select name="rows[{{ $i }}][sales_ledger_id]"><option value="">Choose...</option>@foreach ($income as $id => $n)<option value="{{ $id }}" @selected((string) $r['sales_ledger_id'] === (string) $id)>{{ $n }}</option>@endforeach</select></td>
                <td><select name="rows[{{ $i }}][expense_ledger_id]"><option value="">Automatic</option>@foreach ($expense as $id => $n)<option value="{{ $id }}" @selected((string) $r['expense_ledger_id'] === (string) $id)>{{ $n }}</option>@endforeach</select></td>
                <td><input name="rows[{{ $i }}][purchase_cost]" class="num stock" inputmode="decimal" value="{{ $r['purchase_cost'] }}"></td>
                <td><input name="rows[{{ $i }}][quantity_on_hand]" class="num stock" inputmode="decimal" value="{{ $r['quantity_on_hand'] }}"></td>
                <td><button type="button" class="ghost sm rm" title="Remove row">&times;</button></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    <div class="actions" style="margin-top:12px"><button type="button" class="ghost sm" id="add">+ Add another item</button></div>
    <div class="actions" style="margin-top:20px"><button>Save items</button><button name="save_and_new" value="1" class="ghost">Save &amp; add more</button><a class="btn ghost" href="{{ route('items.index') }}">Cancel</a></div>
</form>

<script>
(function () {
    var body = document.querySelector('#rows tbody');
    function sync(row) { var inv = row.querySelector('.type').value === 'inventory'; row.querySelectorAll('.stock').forEach(function (i) { i.disabled = !inv; }); }
    function wire(row) {
        row.querySelector('.type').addEventListener('change', function () { sync(row); });
        row.querySelector('.rm').addEventListener('click', function () { if (body.children.length > 1) { row.remove(); renumber(); } });
        sync(row);
    }
    function renumber() { [].forEach.call(body.children, function (r, i) { r.querySelectorAll('[name^="rows["]').forEach(function (el) { el.name = el.name.replace(/^rows\[\d+\]/, 'rows[' + i + ']'); }); }); }
    document.getElementById('add').addEventListener('click', function () {
        var row = body.lastElementChild.cloneNode(true);
        row.querySelectorAll('input').forEach(function (i) { i.value = i.classList.contains('num') ? '0.00' : ''; });
        row.querySelectorAll('select').forEach(function (s) { if (!s.classList.contains('type')) s.selectedIndex = s.name.indexOf('sales_ledger_id') > -1 ? Math.min(1, s.options.length - 1) : 0; else s.value = 'service'; });
        body.appendChild(row); renumber(); wire(row);
    });
    [].forEach.call(body.children, wire);
})();
</script>
@endsection
