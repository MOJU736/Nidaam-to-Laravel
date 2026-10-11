@extends('layouts.main')
@section('title', 'Workers')
@section('content')
@include('construction._tabs')
@php use App\Support\Money; @endphp
<div class="bar"><h1 style="margin:0">Site workers</h1></div>
<form class="card" method="POST" action="{{ $edit ? route('construction.workers.update', $edit->id) : route('construction.workers.store') }}" style="margin-bottom:16px">
    @csrf @if ($edit) @method('PUT') @endif
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <h2 style="margin-top:0">{{ $edit ? 'Edit '.$edit->name : 'Add a worker' }}</h2>
    <div class="row2">
        <div><label for="name" style="margin-top:0">Name</label><input id="name" name="name" maxlength="255" required value="{{ old('name', $edit->name ?? '') }}"></div>
        <div><label for="trade" style="margin-top:0">Trade</label><input id="trade" name="trade" maxlength="100" placeholder="Mason, carpenter..." value="{{ old('trade', $edit->trade ?? '') }}"></div>
    </div>
    <div class="row2">
        <div><label for="pay_type">Paid</label><select id="pay_type" name="pay_type">@foreach ($payTypes as $k => $l)<option value="{{ $k }}" @selected(old('pay_type', $edit->pay_type ?? 'daily') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label for="rate">Daily rate / monthly salary</label><input id="rate" name="rate" inputmode="decimal" required value="{{ old('rate', $edit->rate ?? '') }}"></div>
    </div>
    <div class="row2">
        <div><label for="phone">Phone</label><input id="phone" name="phone" maxlength="50" value="{{ old('phone', $edit->phone ?? '') }}"></div>
        <div><label for="default_project_id">Usual project</label><select id="default_project_id" name="default_project_id"><option value="">None</option>@foreach ($projects as $p)<option value="{{ $p['id'] }}" @selected((int) old('default_project_id', $edit->default_project_id ?? 0) === $p['id'])>{{ $p['label'] }}</option>@endforeach</select></div>
    </div>
    <label for="status">Status</label><select id="status" name="status" style="max-width:200px"><option value="active">Active</option><option value="inactive" @selected(old('status', $edit->status ?? '') === 'inactive')>Inactive</option></select>
    <div class="actions" style="margin-top:14px"><button>{{ $edit ? 'Save worker' : 'Add worker' }}</button>@if ($edit)<a class="btn ghost" href="{{ route('construction.workers') }}">Cancel</a>@endif</div>
</form>
<div class="card tbl">
    <table>
        <thead><tr><th>Name</th><th>Trade</th><th>Paid</th><th class="num">Rate</th><th>Usual project</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($workers as $w)
            <tr><td>{{ $w->name }}@if ($w->phone)<div class="muted" style="font-size:12px">{{ $w->phone }}</div>@endif</td><td>{{ $w->trade }}</td><td>{{ $payTypes[$w->pay_type] ?? $w->pay_type }}</td><td class="num">{{ Money::format($w->rate) }}</td>
                <td>{{ $w->project_name ? '#'.$w->project_number.' '.$w->project_name : '' }}</td><td>{{ ucfirst($w->status) }}</td><td style="text-align:right"><a class="btn ghost sm" href="{{ route('construction.workers', ['edit' => $w->id]) }}">Edit</a></td></tr>
        @empty
            <tr><td colspan="7" class="muted" style="text-align:center;padding:20px">No workers yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
