@extends('layouts.main')
@section('title', 'Groups')
@section('content')
@include('admin._tabs')
<div class="bar"><div><h1>Groups &amp; permissions</h1><div class="muted">A group is a role. Everyone in a group can do what the group is allowed to; a person belongs to one group.</div></div><a class="btn" href="{{ route('admin.groups.create') }}">{{ \App\Support\Icon::svg('plus', 16) }}New group</a></div>
<div class="card tbl"><table>
    <thead><tr><th>Group</th><th>Description</th><th class="num">People</th><th class="num">Things allowed</th><th></th></tr></thead>
    <tbody>
    @foreach ($groups as $g)
        <tr><td><a href="{{ route('admin.groups.edit', $g->id) }}">{{ $g->name }}</a></td><td>{{ $g->description }}</td><td class="num">{{ $counts[$g->id] ?? 0 }}</td>
            <td class="num">@if (in_array(strtolower($g->name), $fixed, true))<span class="muted">fixed</span>@else{{ $ticks[$g->id] ?? 0 }}@endif</td>
            <td style="text-align:right;white-space:nowrap"><a class="btn ghost sm" href="{{ route('admin.groups.edit', $g->id) }}">{{ in_array(strtolower($g->name), $fixed, true) ? 'View' : 'Edit' }}</a>
                @if (! in_array(strtolower($g->name), array_merge($fixed, ['manager', 'accountant', 'data_entry_operator']), true) && empty($counts[$g->id]))<form class="inline" method="POST" action="{{ route('admin.groups.destroy', $g->id) }}" onsubmit="return confirm('Delete the group {{ $g->name }}?')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form>@endif</td></tr>
    @endforeach
    </tbody>
</table></div>
@endsection
