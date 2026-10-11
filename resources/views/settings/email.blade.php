@extends('layouts.main')
@section('title', 'Email settings')
@section('content')
<div class="bar"><h1 style="margin:0">Email settings</h1></div>
<form class="card" method="POST" action="{{ route('settings.email') }}" style="max-width:720px" autocomplete="off">
    @csrf @method('PUT')
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <p style="margin-top:0">Documents (invoices, quotations, receipts, statements...) are emailed from this company's own mail account, so replies and spam checks work in the company's name. Nothing is ever sent on its own: you click Send each time.</p>
    <label style="font-weight:400;margin-top:0"><input type="checkbox" name="email_use_default" value="1" @checked(old('email_use_default', $s->email_use_default))> Use the default sender @if ($defaultReady)<span class="muted">(set up by an administrator)</span>@else<span class="muted">(none has been set up yet)</span>@endif</label>
    <div class="row2" style="margin-top:8px">
        <div><label for="email_host">SMTP server</label><input id="email_host" name="email_host" maxlength="255" placeholder="smtp.example.com" value="{{ old('email_host', $s->email_host) }}"></div>
        <div><label for="email_port">Port</label><input id="email_port" type="number" name="email_port" min="1" max="65535" placeholder="587" value="{{ old('email_port', $s->email_port ?: 587) }}"></div>
    </div>
    <label style="font-weight:400"><input type="checkbox" name="email_tls" value="1" @checked(old('email_tls', $s->email_tls))> Use TLS <span class="muted">(port 465 always uses SSL; other ports use TLS when ticked)</span></label>
    <div class="row2">
        <div><label for="email_username">Username</label><input id="email_username" name="email_username" maxlength="255" value="{{ old('email_username', $s->email_username) }}"></div>
        <div><label for="email_password">Password</label><input id="email_password" type="password" name="email_password" maxlength="255" placeholder="{{ $s->email_password ? 'saved - leave blank to keep it' : '' }}" autocomplete="new-password"></div>
    </div>
    <label for="email_from">Send from</label><input id="email_from" type="email" name="email_from" maxlength="255" placeholder="accounts@yourcompany.com" value="{{ old('email_from', $s->email_from) }}">
    <h2>Send a test</h2>
    <div class="row2"><div><label for="test_to" style="margin-top:0">Test address</label><input id="test_to" name="test_to" placeholder="you@example.com" value="{{ old('test_to') }}"></div><div></div></div>
    <div class="actions" style="margin-top:20px"><button>Save</button><button name="then" value="test" class="ghost">Save &amp; send test email</button></div>
</form>
@endsection
