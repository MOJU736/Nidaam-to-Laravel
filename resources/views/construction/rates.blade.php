@extends('layouts.main')
@section('title', 'Rate list')
@section('content')
@include('construction._tabs')
@php use App\Support\Money; use App\Services\Construction\ConstructionService as C; @endphp
<div class="bar"><h1>Rate list</h1></div>
<p class="muted" style="margin-top:0;max-width:720px">Descriptions with a unit, rate and cost type. Typing one of these on an estimate line fills the rest in. Estimates can add new descriptions here when you save them.</p>
<form class="card" method="POST" action="{{ $edit ? route('construction.rates.update', $edit->id) : route('construction.rates.store') }}" style="margin-bottom:16px">
    @csrf @if ($edit) @method('PUT') @endif
    @foreach ($errors->all() as $er)<p class="error">{{ $er }}</p>@endforeach
    <div class="actions" style="align-items:end;flex-wrap:wrap">
        <div style="flex:1;min-width:260px"><label style="margin-top:0">Description</label><input name="description" maxlength="500" required value="{{ old('description', $edit->description ?? '') }}"></div>
        <div><label style="margin-top:0">Unit</label><select name="unit"><option value=""></option>@foreach ($units as $u)<option value="{{ $u }}" @selected(old('unit', $edit->unit ?? '') === $u)>{{ $u }}</option>@endforeach</select></div>
        <div><label style="margin-top:0">Rate</label><input name="rate" inputmode="decimal" required style="width:110px" value="{{ old('rate', $edit->rate ?? '') }}"></div>
        <div><label style="margin-top:0">Cost type</label><select name="cost_type">@foreach (C::COST_TYPES as $k => $l)<option value="{{ $k }}" @selected(old('cost_type', $edit->cost_type ?? 'materials') === $k)>{{ $l }}</option>@endforeach</select></div>
        <button>{{ $edit ? 'Save rate' : 'Add rate' }}</button>@if ($edit)<a class="btn ghost" href="{{ route('construction.rates') }}">Cancel</a>@endif
    </div>
</form>
<div class="card tbl" style="margin-bottom:16px">
    <table>
        <thead><tr><th>Description</th><th>Unit</th><th>Cost type</th><th class="num">Rate</th><th></th></tr></thead>
        <tbody>
        @forelse ($rates as $r)
            <tr><td>{{ $r->description }}</td><td>{{ $r->unit }}</td><td>{{ C::COST_TYPES[$r->cost_type] ?? $r->cost_type }}</td><td class="num">{{ Money::format($r->rate) }}</td>
                <td style="text-align:right;white-space:nowrap"><a class="btn ghost sm" href="{{ route('construction.rates', ['edit' => $r->id]) }}">Edit</a>
                    <form class="inline" method="POST" action="{{ route('construction.rates.destroy', $r->id) }}" onsubmit="return confirm('Delete this rate?')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form></td></tr>
        @empty
            <tr><td colspan="5" class="muted" style="text-align:center;padding:20px">No rates yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="card" id="units" style="max-width:640px">
    <h2 style="margin-top:0">Units</h2>
    <p class="muted">Standard: {{ implode(', ', \App\Services\Construction\EstimateService::UNITS) }}. Add your own, e.g. roll.</p>
    <div class="actions" style="margin-bottom:8px">@foreach ($ownUnits as $u)<form class="inline" method="POST" action="{{ route('construction.units.destroy') }}">@csrf @method('DELETE')<input type="hidden" name="name" value="{{ $u }}"><span class="pill">{{ $u }} <button class="ghost sm" title="Remove this unit" style="padding:0 6px">&times;</button></span></form>@endforeach</div>
    <form method="POST" action="{{ route('construction.units.store') }}">@csrf<div class="actions" style="align-items:end"><div><label style="margin-top:0">New unit</label><input name="name" maxlength="20" style="width:140px" required></div><button class="ghost">Add</button></div></form>
</div>
@endsection
