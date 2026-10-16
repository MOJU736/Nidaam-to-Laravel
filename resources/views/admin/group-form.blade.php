@extends('layouts.main')
@section('title', $g ? 'Group '.$g->name : 'New group')
@section('content')
@include('admin._tabs')
<a class="back" href="{{ route('admin.groups') }}">&larr; Groups</a>
<div class="bar"><h1>{{ $g ? 'Group: '.$g->name : 'New group' }}</h1></div>
<form method="POST" action="{{ $g ? route('admin.groups.update', $g->id) : route('admin.groups.store') }}" class="docmain" style="max-width:900px">
    @csrf
    @if ($g) @method('PUT') @endif
    @foreach ($errors->all() as $e)<div class="flash bad">{{ $e }}</div>@endforeach
    <div class="card">
    <div class="row2" style="max-width:700px">
        <div><label for="name" style="margin-top:0">Name *</label>@if ($g)<input id="name" value="{{ $g->name }}" readonly>@else<input id="name" name="name" maxlength="25" required value="{{ old('name') }}" placeholder="e.g. cashier">@endif</div>
        <div><label for="description" style="margin-top:0">Description</label><input id="description" name="description" maxlength="100" value="{{ old('description', $g->description ?? '') }}"></div>
    </div>
    </div>
    @if ($fixed)<div class="card"><strong>{{ $fixed }}</strong></div>
    @elseif ($g)
        <p class="muted" style="margin:0">Tick what people in this group may do. A module (Sales, Purchases, ...) must be opened before its create / change / void / delete ticks matter. Administrators always can do everything.</p>
        @foreach ($sections as $title => $cols)
            <div class="card">
                <h3 class="sec">{{ $title }}</h3>
                <div class="toggles">
                    @foreach ($cols as $col => $label)<label class="chk"><input type="checkbox" name="perm[]" value="{{ $col }}" @checked((int) ($row[$col] ?? 0) === 1)> {{ $label }}</label>@endforeach
                </div>
            </div>
        @endforeach
    @else
        <p class="muted">After you create it, you choose what it may do.</p>
    @endif
    <div class="savebar"><button>{{ $g ? 'Save' : 'Create group' }}</button><a class="btn ghost" href="{{ route('admin.groups') }}">Cancel</a></div>
</form>
@endsection
