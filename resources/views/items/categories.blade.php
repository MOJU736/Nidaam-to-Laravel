@extends('layouts.main')
@section('title', 'Item categories')
@section('content')
@include('items._tabs')
<div class="bar"><h1>Categories</h1></div>
<form class="card" method="POST" action="{{ route('items.categories') }}" style="margin-bottom:16px">
    @csrf
    <div class="actions" style="align-items:end"><div style="flex:1;max-width:360px"><label for="name" style="margin-top:0">New category</label><input id="name" name="name" maxlength="255" required placeholder="e.g. Renewal services"></div><button>Add</button></div>
</form>
<div class="card tbl">
    <table>
        <thead><tr><th>Name</th><th class="num">Items</th><th></th></tr></thead>
        <tbody>
        @forelse ($categories as $c)
            <tr>
                <td>
                    <form class="inline" method="POST" action="{{ route('items.categories.update', $c->id) }}">@csrf @method('PUT')
                        <input name="name" value="{{ $c->name }}" maxlength="255" style="width:260px;display:inline-block"> <button class="ghost sm">Rename</button></form>
                </td>
                <td class="num"><a href="{{ route('items.index', ['category_id' => $c->id]) }}">{{ $c->items_count }}</a></td>
                <td style="text-align:right"><form class="inline" method="POST" action="{{ route('items.categories.destroy', $c->id) }}" onsubmit="return confirm('Delete this category? Its items stay, uncategorised.')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form></td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted" style="text-align:center;padding:20px">No categories yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
