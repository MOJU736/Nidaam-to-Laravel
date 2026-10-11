@extends('layouts.main')
@section('title', 'Site stock')
@section('content')
@include('construction._tabs')
@php use App\Support\Money; $q = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
@foreach ($errors->all() as $er)<div class="flash bad">{{ $er }}</div>@endforeach
<div class="bar"><h1 style="margin:0">Site stock</h1></div>
<p class="muted" style="margin-top:0;max-width:760px">The main store holds whatever the sites don't. <strong>Move</strong> stock between stores (nothing is posted), or <strong>issue</strong> it on a project: it leaves the store and the item, and the cost (at the item's cost) is posted to Site Materials Used as a Materials cost of the project.</p>
<div class="card tbl" style="margin-bottom:16px">
    <table>
        <thead><tr><th>Item</th><th class="num">Main store</th>@foreach ($stores as $s)<th class="num">{{ $s->name }}</th>@endforeach<th class="num">Total</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr><td>{{ $r->name }}</td><td class="num">{{ $q($r->main) }}</td>@foreach ($stores as $s)<td class="num">{{ $q($r->sites[$s->id] ?? 0) }}</td>@endforeach<td class="num"><strong>{{ $q($r->quantity_on_hand) }}</strong></td></tr>
        @empty
            <tr><td colspan="{{ 3 + count($stores) }}" class="muted" style="text-align:center;padding:20px">No stock on hand. Stock is bought with a bill (Inventory items).</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="row2">
    <form class="card" method="POST" action="{{ route('construction.stock.move') }}">@csrf
        <h2 style="margin-top:0">Move or issue stock</h2>
        <label style="margin-top:0">What</label><select name="kind" id="kind"><option value="transfer">Move to another store</option><option value="issue">Issue to a project (use it up)</option></select>
        <div class="row2"><div><label>Item</label><select name="item_id" required><option value="">Choose...</option>@foreach ($items as $it)<option value="{{ $it->id }}">{{ $it->name }}</option>@endforeach</select></div><div><label>Quantity</label><input name="qty" inputmode="decimal" required></div></div>
        <div class="row2"><div><label>From</label><select name="from_store_id"><option value="0">Main store</option>@foreach ($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
            <div id="toBox"><label>To</label><select name="to_store_id"><option value="0">Main store</option>@foreach ($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
            <div id="projBox" hidden><label>Project</label><select name="project_id"><option value="">Choose...</option>@foreach ($projects as $p)<option value="{{ $p['id'] }}">{{ $p['label'] }}</option>@endforeach</select></div></div>
        <div class="row2"><div><label>Date</label><input type="date" name="date" required value="{{ now()->toDateString() }}"></div><div><label>Notes</label><input name="notes" maxlength="500"></div></div>
        <div class="actions" style="margin-top:12px"><button>Save</button></div>
    </form>
    <form class="card" method="POST" action="{{ route('construction.stock.stores') }}">@csrf
        <h2 style="margin-top:0">Add a site store</h2>
        <label style="margin-top:0">Name</label><input name="name" maxlength="255" placeholder="Leave empty to name it after the project">
        <label>Project <span class="muted">(optional)</span></label><select name="project_id"><option value="">None</option>@foreach ($projects as $p)<option value="{{ $p['id'] }}">{{ $p['label'] }}</option>@endforeach</select>
        <div class="actions" style="margin-top:12px"><button class="ghost">Add store</button></div>
    </form>
</div>
<div class="card tbl" style="margin-top:16px">
    <h2 style="margin-top:0">Movements</h2>
    <table>
        <thead><tr><th>#</th><th>Date</th><th>What</th><th>Item</th><th class="num">Qty</th><th class="num">Cost</th><th></th></tr></thead>
        <tbody>
        @forelse ($moves as $m)
            <tr style="{{ $m->status === 'void' ? 'text-decoration:line-through;opacity:.6' : '' }}"><td>{{ $m->number }}</td><td>{{ Money::date($m->date) }}</td>
                <td>@if ($m->kind === 'issue')Issued from {{ $names[$m->from_store_id] ?? '' }} to #{{ $m->project_number }} {{ $m->project_name }}@else{{ $names[$m->from_store_id] ?? '' }} → {{ $names[$m->to_store_id] ?? '' }}@endif</td>
                <td>{{ $m->item_name }}</td><td class="num">{{ $q($m->qty) }}</td><td class="num">{{ $m->kind === 'issue' ? Money::format($m->amount) : '' }}</td>
                <td style="text-align:right">@if ($m->status === 'posted')<form class="inline" method="POST" action="{{ route('construction.stock.void', $m->id) }}" onsubmit="return confirm('Void this movement?')">@csrf<button class="danger sm">Void</button></form>@endif</td></tr>
        @empty
            <tr><td colspan="7" class="muted" style="text-align:center;padding:20px">No movements yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<script>
(function () { var k = document.getElementById('kind'); function f() { var i = k.value === 'issue'; document.getElementById('toBox').hidden = i; document.getElementById('projBox').hidden = !i; } k.addEventListener('change', f); f(); })();
</script>
@endsection
