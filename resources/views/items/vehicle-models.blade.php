@extends('layouts.main')
@section('title', 'Vehicle pricing')
@section('content')
@include('items._tabs')
<div class="bar"><h1>Vehicle pricing</h1></div>
<p class="muted" style="margin-top:0">Vehicle models (Prado, Hilux ...) each item can carry a special price for. A customer's vehicle is tagged with a model so invoices pick the right price.</p>
<form class="card" method="POST" action="{{ route('items.vehicles') }}" style="margin-bottom:16px">
    @csrf
    <div class="actions" style="align-items:end"><div style="flex:1;max-width:360px"><label for="name" style="margin-top:0">New vehicle model</label><input id="name" name="name" maxlength="255" required></div><button>Add</button></div>
</form>
<div class="card tbl">
    <table>
        <thead><tr><th>Model</th><th></th></tr></thead>
        <tbody>
        @forelse ($models as $m)
            <tr>
                <td><form class="inline" method="POST" action="{{ route('items.vehicles.update', $m->id) }}">@csrf @method('PUT')<input name="name" value="{{ $m->name }}" maxlength="255" style="width:260px;display:inline-block"> <button class="ghost sm">Rename</button></form></td>
                <td><div class="actions" style="justify-content:flex-end"><a class="btn ghost sm" href="{{ route('items.vehicles.prices', $m->id) }}">Prices</a>
                    <form class="inline" method="POST" action="{{ route('items.vehicles.destroy', $m->id) }}" onsubmit="return confirm('Delete this model and its special prices?')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form></div></td>
            </tr>
        @empty
            <tr><td colspan="2" class="muted" style="text-align:center;padding:20px">No vehicle models yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
