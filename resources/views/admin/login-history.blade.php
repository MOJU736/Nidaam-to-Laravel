@extends('layouts.main')
@section('title', 'Login history')
@section('content')
@include('admin._tabs')
<div class="bar"><div><h1>Login history{{ $user ? ' · '.$user->username : '' }}</h1><div class="muted">Every sign-in, sign-out and wrong password, newest first (the latest 200).</div></div>
    <form method="GET" class="actions" style="align-items:center">
        <select name="user" onchange="this.form.submit()" style="width:auto"><option value="">Everyone</option>@foreach ($users as $u)<option value="{{ $u->id }}" @selected($user && $user->id === $u->id)>{{ $u->username }}</option>@endforeach</select>
        <select name="event" onchange="this.form.submit()" style="width:auto"><option value="">All events</option>@foreach (['signin' => 'Sign-ins', 'logout' => 'Sign-outs', 'failed' => 'Wrong passwords', 'locked' => 'Locked out', 'reset_request' => 'Reset requests', 'reset_done' => 'Passwords chosen'] as $k => $l)<option value="{{ $k }}" @selected($event === $k)>{{ $l }}</option>@endforeach</select>
    </form></div>
<p class="muted" style="margin-top:0;font-size:13.5px">A person is signed out automatically after 30 minutes without activity, and when they sign in on another device.</p>
<div class="card tbl"><table>
    <thead><tr><th>When</th><th>Who</th><th>What</th><th>Device</th><th>IP address</th><th>Ended</th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
        <tr>
            <td style="white-space:nowrap">{{ date('j M Y, g:i a', $r['created_at']) }}</td>
            <td>{{ trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')) ?: ($r['username'] ?? $r['identity']) }} <span class="muted">{{ $r['identity'] }}</span></td>
            <td>{{ ['signin' => 'Signed in', 'logout' => 'Signed out', 'failed' => 'Wrong password', 'locked' => 'Locked out', 'reset_request' => 'Asked for a reset link', 'reset_done' => 'Chose a new password'][$r['event']] ?? $r['event'] }}{{ $r['how'] && $r['how'] !== 'password' ? ' ('.$r['how'].')' : '' }}@if ($r['new_device']) <span class="pill owes">new device</span>@endif</td>
            <td>{{ $r['device'] ?: '' }}</td><td>{{ $r['ip_address'] }}</td>
            <td>@if ($r['event'] === 'signin'){{ $r['ended_at'] ? date('j M, g:i a', $r['ended_at']).' · '.($reasons[$r['end_reason']] ?? $r['end_reason']) : ($r['last_seen'] && $r['last_seen'] > time() - 2400 ? 'Active now' : 'Still open') }}@endif</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Nothing yet.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
