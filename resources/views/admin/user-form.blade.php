@extends('layouts.main')
@section('title', $u ? 'Edit user' : 'New user')
@section('content')
@include('admin._tabs')
<div class="bar"><h1 style="margin:0">{{ $u ? 'Edit '.$u->username : 'New user' }}</h1><a class="muted" href="{{ route('admin.users') }}">&larr; Users</a></div>
<form class="card" method="POST" action="{{ $u ? route('admin.users.update', $u->id) : route('admin.users.store') }}" style="max-width:760px" autocomplete="off">
    @csrf
    @if ($u) @method('PUT') @endif
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <div class="row2"><div><label for="username" style="margin-top:0">Username *</label><input id="username" name="username" maxlength="25" required value="{{ old('username', $u->username ?? '') }}"></div>
        <div><label for="email" style="margin-top:0">Email *</label><input id="email" type="email" name="email" maxlength="30" required value="{{ old('email', $u->email ?? '') }}"></div></div>
    <div class="row2" style="margin-top:8px;grid-template-columns:1fr 1fr 1fr 1fr">
        <div><label for="first_name">First name *</label><input id="first_name" name="first_name" maxlength="11" required value="{{ old('first_name', $u->first_name ?? '') }}"></div>
        <div><label for="last_name">Last name</label><input id="last_name" name="last_name" maxlength="11" value="{{ old('last_name', $u->last_name ?? '') }}"></div>
        <div><label for="company">Company / role</label><input id="company" name="company" maxlength="25" value="{{ old('company', $u->company ?? '') }}"></div>
        <div><label for="phone">Phone (digits)</label><input id="phone" name="phone" inputmode="numeric" maxlength="15" value="{{ old('phone', $u->phone ?? '') }}"></div>
    </div>
    <div class="row2" style="margin-top:8px"><div><label for="password">{{ $u ? 'New password' : 'Password' }} <span class="muted">(8+ characters, letters and numbers{{ $u ? '; leave blank to keep the current one' : '' }})</span></label><input id="password" type="password" name="password" autocomplete="new-password" @if (! $u) @endif>@if (! $u)<label style="font-weight:400;margin:6px 0 0"><input type="checkbox" name="send_invite" value="1" id="inv" @checked(old('send_invite'))> Instead, email an invite link: the person chooses their own password</label>@endif</div>
        <div><label for="group_id">Group *</label><select id="group_id" name="group_id" required><option value="">Choose a group...</option>@foreach ($groups as $g)<option value="{{ $g->id }}" @selected((string) old('group_id', $groupId) === (string) $g->id)>{{ $g->name }}@if ($g->description) - {{ $g->description }}@endif</option>@endforeach</select></div></div>

    <h3>Companies this person may open</h3>
    <label style="font-weight:400;margin-top:0"><input type="checkbox" name="all_accounts" value="1" id="allAcc" @checked(old('all_accounts', $u->all_accounts ?? false))> All companies (including ones added later)</label>
    <div id="accList" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:4px 14px;margin-top:6px">
        @foreach ($accounts as $a)<label style="font-weight:400;margin:0"><input type="checkbox" name="accounts[]" value="{{ $a->id }}" @checked(in_array((string) $a->id, array_map('strval', (array) old('accounts', $mine)), true))> {{ $a->label }}</label>@endforeach
    </div>
    <div style="margin-top:14px">
        <label style="font-weight:400;margin:0"><input type="checkbox" name="active" value="1" @checked(old('active', $u ? $u->active : true))> Active (can sign in)</label><br>
        <label style="font-weight:400;margin:0"><input type="checkbox" name="must_change_password" value="1" @checked(old('must_change_password', $u->must_change_password ?? false))> Must choose a new password at the next sign-in</label>
    </div>
    <div class="actions" style="margin-top:20px"><button>{{ $u ? 'Save changes' : 'Create user' }}</button><a class="btn ghost" href="{{ route('admin.users') }}">Cancel</a></div>
</form>
@if ($u)<form class="card" method="POST" action="{{ route('admin.users.sign-out', $u->id) }}" style="max-width:760px;margin-top:14px" onsubmit="return confirm('Sign {{ $u->username }} out on every device?')">@csrf<strong>Signed in somewhere they should not be?</strong> <span class="muted">Sign them out on every device; they must sign in again.</span> <button class="ghost sm">Sign out everywhere</button> <a class="btn ghost sm" href="{{ route('admin.login-history', ['user' => $u->id]) }}">Login history</a></form>@endif
@if ($u)<form class="card" method="POST" action="{{ route('admin.users.reset-link', $u->id) }}" style="max-width:760px;margin-top:14px" onsubmit="return confirm('Email {{ $u->email }} a link to choose a new password? It signs them out everywhere.')">@csrf<strong>Forgot their password?</strong> <span class="muted">Email {{ $u->email }} a one-time link (works for 48 hours) so they choose a new one themselves.</span> <button class="ghost sm">Send password link</button></form>@endif
<script>(function () { var all = document.getElementById('allAcc'), list = document.getElementById('accList'); function s() { list.style.opacity = all.checked ? .4 : 1; list.querySelectorAll('input').forEach(function (i) { i.disabled = all.checked; }); } all.addEventListener('change', s); s(); })();</script>
@endsection
