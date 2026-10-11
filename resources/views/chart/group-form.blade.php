@extends('layouts.main')
@section('title', $group ? 'Edit group' : 'New group')
@section('content')
<div class="bar"><h1 style="margin:0">{{ $group ? 'Edit group' : 'New group' }}</h1><a class="muted" href="{{ route('chart') }}">&larr; Chart of Accounts</a></div>

<form class="card" method="POST" action="{{ $group ? route('groups.update', $group->id) : route('groups.store') }}" style="max-width:640px">
    @csrf
    @if ($group) @method('PUT') @endif
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach

    <label for="name">Group name *</label>
    <input id="name" name="name" value="{{ old('name', $group->name ?? '') }}" maxlength="255" required @unless($group) autofocus @endunless>

    <label for="parent_id">Inside *</label>
    <select id="parent_id" name="parent_id" required>
        <option value="">Choose where this group goes...</option>
        @foreach ($groups as $gid => $g)
            <option value="{{ $gid }}" @selected((int) old('parent_id', $picked) === $gid)>{{ str_repeat('— ', $g['depth']).$g['label'] }}</option>
        @endforeach
    </select>
    <p class="muted" style="font-size:13px">A group can't go inside itself or a group under it.</p>

    @if ($group)
        <p class="muted" style="font-size:13px">Code {{ $group->code ?: '—' }} &middot; holds {{ $childGroups }} group{{ $childGroups === 1 ? '' : 's' }} and {{ $childAccounts }} account{{ $childAccounts === 1 ? '' : 's' }}. Moving it gives it a new code, and everything inside follows.</p>
    @endif

    <div class="actions" style="margin-top:20px">
        <button type="submit">{{ $group ? 'Save changes' : 'Create group' }}</button>
        <a class="btn ghost" href="{{ route('chart', $group ? ['show' => 'g'.$group->id] : []) }}">Cancel</a>
    </div>
</form>
@endsection
