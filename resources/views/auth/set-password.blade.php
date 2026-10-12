@extends('layouts.app')
@section('title', 'Choose a password')
@section('body')
<div class="entry">
    @include('auth._aside')
    <div class="pane">
    @if ($bad)
        <div class="card">
            <h1>This link has expired</h1>
            <p>The link to set a password works once and only for a short time. It may have been used already, or it has run out.</p>
            <a class="btn full" href="{{ route('password.forgot') }}">Get a new link</a>
            <p style="text-align:center;margin-bottom:0"><a href="{{ route('login') }}">Back to sign in</a></p>
        </div>
    @else
        <form class="card" method="POST" action="{{ $action }}" autocomplete="off">
            @csrf
            <h1>{{ $mode === 'first' ? 'Choose your own password' : 'Choose a new password' }}</h1>
            <p class="muted" style="margin-top:0">@if ($mode === 'first')The password you were given is only for your first sign-in. Choose one only you know.@else Hello {{ $person->first_name ?: $person->username }}. Your username is <strong>{{ $person->username }}</strong>.@endif</p>
            <label for="new">New password</label>
            <input id="new" type="password" name="new" autocomplete="new-password" autofocus required>
            <label for="new_confirm">Type it again</label>
            <input id="new_confirm" type="password" name="new_confirm" autocomplete="new-password" required>
            <p class="muted" style="font-size:13px">At least 8 characters, with letters and numbers, and nothing easy to guess.</p>
            @foreach ($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach
            <button class="full" type="submit">Save my password</button>
        </form>
    @endif
    </div>
</div>
@endsection