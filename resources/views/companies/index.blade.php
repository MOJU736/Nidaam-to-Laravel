@extends('layouts.app')
@section('title', 'Choose company')
@section('body')
<div class="wrap">
    <div class="bar">
        <h1 style="margin:0">Choose a company</h1>
        <form class="inline" method="POST" action="{{ route('logout') }}">@csrf<button class="link">Sign out ({{ auth()->user()->username }})</button></form>
    </div>
    <div class="card">
        @foreach ($errors->all() as $error)<p class="error">{{ $error }}</p>@endforeach
        <ul class="list">
            @foreach ($accounts as $account)
                <li>
                    <span>
                        <b>{{ $account->name ?: $account->label }}</b>
                        <span class="muted"> &middot; FY {{ $account->fy_start->format('Y-m-d') }} to {{ $account->fy_end->format('Y-m-d') }}</span>
                        @if ($account->account_locked)<span class="muted"> &middot; locked</span>@endif
                    </span>
                    <form class="inline" method="POST" action="{{ route('companies.activate', $account->id) }}">
                        @csrf
                        <button @disabled($account->account_locked)>{{ (string) $active === (string) $account->id ? 'Open (current)' : 'Open' }}</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </div>
</div>
@endsection
