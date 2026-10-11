@extends('layouts.app')
@section('title', 'Sign in')
@section('body')
<div class="center">
    <form class="card" method="POST" action="{{ url('/login') }}">
        @csrf
        <h1>Nidaam</h1>
        @if (session('message'))<div class="flash ok">{{ session('message') }}</div>@endif
        <label for="identity">Username or email</label>
        <input id="identity" name="identity" value="{{ old('identity', session('identity_hint')) }}" autocomplete="username" autofocus required>
        <label for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
        <label style="font-weight:400"><input type="checkbox" name="remember" value="1"> Keep me signed in on this device</label>
        @foreach ($errors->all() as $error)
            <p class="error">{{ $error }}</p>
        @endforeach
        <button class="full" type="submit">Sign in</button>
        <p style="text-align:center;margin-bottom:0"><a href="{{ route('password.forgot') }}">Forgot your password?</a></p>
    </form>
</div>
@endsection
