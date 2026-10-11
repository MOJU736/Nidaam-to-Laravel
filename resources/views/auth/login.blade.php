@extends('layouts.app')
@section('title', 'Sign in')
@section('body')
<div class="center">
    <form class="card" method="POST" action="{{ url('/login') }}">
        @csrf
        <h1>Nidaam</h1>
        <label for="identity">Username or email</label>
        <input id="identity" name="identity" value="{{ old('identity') }}" autocomplete="username" autofocus required>
        <label for="password">Password</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>
        @foreach ($errors->all() as $error)
            <p class="error">{{ $error }}</p>
        @endforeach
        <button class="full" type="submit">Sign in</button>
    </form>
</div>
@endsection
