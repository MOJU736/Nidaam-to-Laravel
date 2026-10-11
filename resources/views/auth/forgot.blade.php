@extends('layouts.app')
@section('title', 'Forgot password')
@section('body')
<div class="center">
    <form class="card" method="POST" action="{{ route('password.forgot.send') }}">
        @csrf
        <h1>Forgot your password?</h1>
        @if ($sent)
            <div class="flash ok">If that username or email belongs to an active account that has an email address, we have sent it a link to choose a new password. The link works for 60 minutes. Check your spam folder too.</div>
            <p class="muted">Nothing arrived? Ask your administrator to send you a new link.</p>
            <a class="btn ghost full" href="{{ route('login') }}">Back to sign in</a>
        @else
            <p class="muted" style="margin-top:0">Type your username or email address and we will email you a link to choose a new password.</p>
            <label for="identity">Username or email</label>
            <input id="identity" name="identity" value="{{ old('identity') }}" autocomplete="username" autofocus required>
            @foreach ($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach
            <button class="full" type="submit">Send me the link</button>
            <p style="text-align:center;margin-bottom:0"><a href="{{ route('login') }}">Back to sign in</a></p>
        @endif
    </form>
</div>
@endsection