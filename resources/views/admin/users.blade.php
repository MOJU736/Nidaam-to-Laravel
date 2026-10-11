@extends('layouts.main')
@section('title', 'Users')
@section('content')
@include('admin._tabs')
<div class="bar"><h1 style="margin:0">Users</h1><a class="btn" href="{{ route('admin.users.create') }}">+ New user</a></div>
<div class="card tbl"><table>
    <thead><tr><th>Username</th><th>Name</th><th>Email</th><th>Group</th><th>Companies</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @foreach ($users as $u)
        @php $mine = $u->all_accounts ? null : (array) json_decode((string) $u->accounts, true); @endphp
        <tr>
            <td><a href="{{ route('admin.users.edit', $u->id) }}">{{ $u->username }}</a>@if ($u->id === auth()->id()) <span class="pill">you</span>@endif</td>
            <td>{{ $u->displayName() }}</td><td>{{ $u->email }}</td><td>{{ $groupNames[$u->id] ?? '—' }}</td>
            <td>{{ $mine === null || in_array('all', (array) $mine, true) ? 'All companies' : collect($mine)->map(fn ($i) => $accounts[(int) $i] ?? "#$i")->implode(', ') }}</td>
            <td><span class="pill {{ $u->active ? '' : 'overdue' }}">{{ $u->active ? 'Active' : 'Switched off' }}</span></td>
            <td style="text-align:right;white-space:nowrap"><a class="btn ghost sm" href="{{ route('admin.users.edit', $u->id) }}">Edit</a>
                @if ($u->id !== auth()->id())<form class="inline" method="POST" action="{{ route('admin.users.destroy', $u->id) }}" onsubmit="return confirm('Delete {{ $u->username }}? This cannot be undone.')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endsection
