@extends('layouts.main')
@section('title', $g ? 'Group '.$g->name : 'New group')
@section('content')
@include('admin._tabs')
<div class="bar"><h1 style="margin:0">{{ $g ? 'Group: '.$g->name : 'New group' }}</h1><a class="muted" href="{{ route('admin.groups') }}">&larr; Groups</a></div>
<form class="card" method="POST" action="{{ $g ? route('admin.groups.update', $g->id) : route('admin.groups.store') }}">
    @csrf
    @if ($g) @method('PUT') @endif
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <div class="row2" style="max-width:700px">
        <div><label for="name" style="margin-top:0">Name *</label>@if ($g)<input id="name" value="{{ $g->name }}" readonly>@else<input id="name" name="name" maxlength="25" required value="{{ old('name') }}" placeholder="e.g. cashier">@endif</div>
        <div><label for="description" style="margin-top:0">Description</label><input id="description" name="description" maxlength="100" value="{{ old('description', $g->description ?? '') }}"></div>
    </div>
    @if ($fixed)<p style="margin-top:16px"><strong>{{ $fixed }}</strong></p>
    @elseif ($g)
        <p class="muted">Tick what people in this group may do. A module (Sales, Purchases, ...) must be opened before its create / change / void / delete ticks matter. Administrators always can do everything.</p>
        @foreach ($sections as $title => $cols)
            <h3 style="margin-bottom:6px">{{ $title }}</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:4px 16px">
                @foreach ($cols as $col => $label)<label style="font-weight:400;margin:0"><input type="checkbox" name="perm[]" value="{{ $col }}" @checked((int) ($row[$col] ?? 0) === 1)> {{ $label }}</label>@endforeach
            </div>
        @endforeach
    @else
        <p class="muted">After you create it, you choose what it may do.</p>
    @endif
    <div class="actions" style="margin-top:22px"><button>{{ $g ? 'Save' : 'Create group' }}</button><a class="btn ghost" href="{{ route('admin.groups') }}">Cancel</a></div>
</form>
@endsection
