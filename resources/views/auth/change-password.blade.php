@extends('layouts.main')
@section('title', 'Change password')
@section('content')
<div class="bar"><h1 style="margin:0">Change your password</h1></div>
<form class="card" method="POST" action="{{ route('password.change.save') }}" style="max-width:480px" autocomplete="off">
    @csrf
    @foreach ($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach
    <label for="old" style="margin-top:0">Current password</label><input id="old" type="password" name="old" autocomplete="current-password" required>
    <label for="new">New password</label><input id="new" type="password" name="new" autocomplete="new-password" required>
    <label for="new_confirm">Type the new password again</label><input id="new_confirm" type="password" name="new_confirm" autocomplete="new-password" required>
    <p class="muted" style="font-size:13px">At least 8 characters, with letters and numbers, and nothing easy to guess. You will be signed out and sign in again with the new one.</p>
    <div class="actions"><button>Change password</button></div>
</form>
@endsection