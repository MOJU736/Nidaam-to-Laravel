@extends('layouts.app')
@section('title', 'Choose company')
@section('body')
<div class="wrap" style="max-width:1000px;padding-top:40px">
    <div class="bar" style="align-items:center">
        <div style="display:flex;align-items:center;gap:12px">{{ \App\Support\Icon::mark(38) }}<div><h1 style="margin:0;font-size:24px">Choose a company</h1><div class="muted">{{ auth()->user()->displayName() }}, which books do you want to open?</div></div></div>
        <form class="inline" method="POST" action="{{ route('logout') }}">@csrf<button class="ghost sm">Sign out</button></form>
    </div>
    @foreach ($errors->all() as $error)<div class="flash bad">{{ $error }}</div>@endforeach
    <div class="pick">
        @foreach ($accounts as $account)
            @php $name = $account->name ?: $account->label; $current = (string) $active === (string) $account->id; @endphp
            <form method="POST" action="{{ route('companies.activate', $account->id) }}">@csrf
                <button @disabled($account->account_locked)>
                    <span style="display:flex;gap:14px;align-items:center;width:100%">
                        <span style="width:46px;height:46px;border-radius:12px;background:var(--brand-soft);color:var(--brand-dark);display:grid;place-items:center;font:700 17px var(--font-num);flex:none">{{ mb_strtoupper(mb_substr($name, 0, 2)) }}</span>
                        <span style="flex:1;min-width:0">
                            <strong style="display:block;font-size:16px">{{ $name }}</strong>
                            <span class="muted" style="font-weight:400;font-size:13px">Year {{ $account->fy_start->format('j M Y') }} – {{ $account->fy_end->format('j M Y') }}</span>
                            @if ($account->account_locked)<span class="pill" style="margin-left:6px">locked</span>@endif @if ($current)<span class="pill owes" style="margin-left:6px">current</span>@endif
                        </span>
                        <span style="color:var(--muted)">{{ \App\Support\Icon::svg('chevron', 20) }}</span>
                    </span>
                </button>
            </form>
        @endforeach
    </div>
</div>
@endsection
