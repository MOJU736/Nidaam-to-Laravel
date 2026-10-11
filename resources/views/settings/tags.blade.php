@extends('layouts.main')
@section('title', 'Tags')
@section('content')
@include('settings._tabs')
<div class="bar"><h1 style="margin:0">Tags</h1></div>
@if ($locked)<div class="flash bad">This company is locked, so tags cannot be changed.</div>@endif
<p class="muted" style="margin-top:0;max-width:720px">Labels for journal entries. A tag can be put on an entry and used to filter the Entries list. A tag that is on entries cannot be deleted (rename it instead).</p>
<form class="card" method="POST" action="{{ route('settings.tags') }}" style="margin-bottom:16px">
    @csrf
    <div class="actions" style="align-items:end;flex-wrap:wrap">
        <div><label for="title" style="margin-top:0">New tag</label><input id="title" name="title" maxlength="60" required placeholder="e.g. Audit"></div>
        <div><label for="colour" style="margin-top:0">Colour</label><select id="colour" name="colour">@foreach ($colours as $bg => $fg)<option value="{{ $bg }}" style="background:#{{ $bg }};color:#{{ $fg }}">#{{ $bg }}</option>@endforeach</select></div>
        <button @disabled($locked)>Add</button>
    </div>
</form>
<div class="card tbl">
    <table>
        <thead><tr><th>Tag</th><th>Colour</th><th class="num">Entries</th><th></th></tr></thead>
        <tbody>
        @forelse ($tags as $t)
            <tr>
                <form id="tg{{ $t->id }}" method="POST" action="{{ route('settings.tags.update', $t->id) }}">@csrf @method('PUT')</form>
                <td><span class="pill" style="background:#{{ $t->background }};color:#{{ $t->color }};border-color:transparent">{{ $t->title }}</span>
                    <input form="tg{{ $t->id }}" name="title" value="{{ $t->title }}" maxlength="60" style="width:180px;display:inline-block;margin-left:8px"></td>
                <td><select form="tg{{ $t->id }}" name="colour">@foreach ($colours as $bg => $fg)<option value="{{ $bg }}" style="background:#{{ $bg }};color:#{{ $fg }}" @selected(strtoupper($t->background) === $bg)>#{{ $bg }}</option>@endforeach</select></td>
                <td class="num"><a href="{{ route('entries.index', ['tag' => $t->id]) }}">{{ $t->uses }}</a></td>
                <td style="text-align:right;white-space:nowrap"><button form="tg{{ $t->id }}" class="ghost sm" @disabled($locked)>Save</button>
                    <form class="inline" method="POST" action="{{ route('settings.tags.destroy', $t->id) }}" onsubmit="return confirm('Delete this tag?')">@csrf @method('DELETE')<button class="danger sm" @disabled($locked)>Delete</button></form></td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted" style="text-align:center;padding:20px">No tags yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
