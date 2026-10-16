@extends('layouts.main')
@section('title', 'Users')
@section('content')
@include('admin._tabs')
<div class="bar"><div><h1>Users</h1><div class="muted">{{ count($users) }} {{ count($users) === 1 ? 'person' : 'people' }} can sign in to Nidaam</div></div><a class="btn" href="{{ route('admin.users.create') }}">{{ \App\Support\Icon::svg('plus', 16) }}New user</a></div>
<div class="tools"><div></div><input id="q" class="find" type="search" placeholder="Search name, username or email"></div>
<div class="card tbl"><table id="list">
    <thead><tr><th>Username</th><th>Name</th><th>Email</th><th>Group</th><th>Companies</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @foreach ($users as $u)
        @php $mine = $u->all_accounts ? null : (array) json_decode((string) $u->accounts, true); @endphp
        <tr data-text="{{ strtolower($u->username.' '.$u->displayName().' '.$u->email) }}">
            <td><a class="who" href="{{ route('admin.users.edit', $u->id) }}"><span class="avs">{{ mb_strtoupper(mb_substr(trim($u->displayName() ?: $u->username), 0, 1)) }}</span><strong>{{ $u->username }}</strong></a>@if ($u->id === auth()->id()) <span class="pill owes">you</span>@endif</td>
            <td>{{ $u->displayName() }}</td><td>{{ $u->email }}</td><td>{{ $groupNames[$u->id] ?? '—' }}</td>
            <td>{{ $mine === null || in_array('all', (array) $mine, true) ? 'All companies' : collect($mine)->map(fn ($i) => $accounts[(int) $i] ?? "#$i")->implode(', ') }}</td>
            <td><span class="pill {{ $u->active ? '' : 'overdue' }}">{{ $u->active ? 'Active' : 'Switched off' }}</span></td>
            <td style="text-align:right;white-space:nowrap"><a class="btn ghost sm" href="{{ route('admin.users.edit', $u->id) }}">Edit</a>
                @if ($u->id !== auth()->id())<form class="inline" method="POST" action="{{ route('admin.users.destroy', $u->id) }}" onsubmit="return confirm('Delete {{ $u->username }}? This cannot be undone.')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form>@endif</td>
        </tr>
    @endforeach
    </tbody>
</table></div>
<script>(function () { var q = document.getElementById('q'), rows = [].slice.call(document.querySelectorAll('#list tbody tr[data-text]')); q.addEventListener('input', function () { var t = q.value.trim().toLowerCase(); rows.forEach(function (r) { r.hidden = t && r.dataset.text.indexOf(t) === -1; }); }); })();</script>
@endsection
