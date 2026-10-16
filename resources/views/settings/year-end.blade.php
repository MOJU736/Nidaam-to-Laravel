@extends('layouts.main')
@section('title', 'Year-end close')
@section('content')
@include('settings._tabs')
@php
    use App\Services\Settings\YearEndService;
    use App\Support\Money;
    $loss = Money::cmp($ye['profit'], 0) < 0;
@endphp
<div class="bar"><h1>Year-end close</h1></div>
<div class="row2" style="grid-template-columns:2fr 1fr;align-items:start">
    <div class="card">
        <div class="actions" style="justify-content:space-between;align-items:flex-start">
            <div><div class="muted">Financial year</div><div style="font-size:26px;font-weight:700">{{ $ye['name'] }}</div>
                <div class="muted">{{ Money::date($ye['start']) }} – {{ Money::date($ye['end']) }} · {{ number_format($ye['entries']) }} journal entr{{ $ye['entries'] === 1 ? 'y' : 'ies' }}</div></div>
            <div style="text-align:right"><div class="muted">{{ $loss ? 'Loss' : 'Profit' }} {{ $ye['ended'] ? 'for the year' : 'so far' }}</div>
                <div style="font-size:22px;font-weight:700"><a href="{{ route('reports.profit-loss', ['from' => $ye['start'], 'to' => $ye['end']]) }}">{{ Money::format(ltrim($ye['profit'], '-')) }}</a></div></div>
        </div>
        @if ($ye['too_long'])<div class="flash bad" style="margin-top:12px">The financial year in Company settings ends on {{ Money::date($ye['stored_end']) }}. A financial year is twelve months, so this one ends on {{ Money::date($ye['end']) }}. Closing it starts the next twelve months and puts this right.</div>@endif
        @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach

        <h2>Before you close</h2>
        <ul style="list-style:none;padding:0;margin:0">
            @foreach ($ye['checks'] as $c)
                <li style="display:flex;gap:10px;padding:6px 0">
                    <span style="font-size:18px;color:{{ $c['ok'] === null ? 'var(--muted)' : ($c['ok'] ? '#1d9650' : ($c['block'] ? 'var(--danger)' : '#b7791f')) }}">{{ $c['ok'] === null ? '↓' : ($c['ok'] ? '✓' : ($c['block'] ? '✕' : '!')) }}</span>
                    <div><strong>{{ $c['title'] }}</strong><div class="muted" style="font-size:13px">{{ $c['text'] }} @if ($c['url'])<a href="{{ $c['url'] }}">{{ $c['ok'] === null ? 'Go to Backup' : 'Open' }} →</a>@endif</div></div>
                </li>
            @endforeach
        </ul>

        <h2>What closing does</h2>
        <ul class="muted" style="font-size:14px">
            <li>The year's {{ $loss ? 'loss' : 'profit' }} ({{ Money::format(ltrim($ye['profit'], '-')) }}) moves into <strong>Retained Earnings</strong> on the Balance Sheet. Income and expense accounts start the new year at zero.</li>
            <li>The books are closed through <strong>{{ Money::date($ye['end']) }}</strong>, so nothing in the old year can be added, changed or deleted by accident.</li>
            <li>The new financial year, <strong>{{ $ye['next_name'] }}</strong> ({{ Money::date($ye['next_start']) }} – {{ Money::date($ye['next_end']) }}), starts. Customers, vendors, stock, open invoices and bills, and every balance carry on as they are: nothing is copied or posted.</li>
            <li>You can reopen the year here if you need to change something.</li>
        </ul>
        @if ($ye['can_close'])
            <form method="POST" action="{{ route('settings.year-end.close') }}" onsubmit="return confirm('Close the {{ $ye['name'] }} financial year? Nothing dated on or before {{ Money::date($ye['end']) }} can be changed until the year is reopened.')">@csrf<button>Close {{ $ye['name'] }}</button></form>
        @else
            <button disabled>Close {{ $ye['name'] }}</button>
            <span class="muted" style="margin-left:8px">{{ $ye['blocked'] ? 'Fix the items marked ✕ first.' : 'This year runs until '.Money::date($ye['end']).'; you can close it from '.Money::date($ye['next_start']).'.' }}</span>
        @endif
    </div>
    <div class="card">
        <h2 style="margin-top:0">Closed years</h2>
        @forelse ($history as $h)
            @php $hs = substr((string) $h->fy_start, 0, 10); $he = substr((string) $h->fy_end, 0, 10); @endphp
            <div style="padding:8px 0;border-top:1px solid var(--line);{{ $h->reopened_at ? 'opacity:.6' : '' }}">
                <div class="actions" style="justify-content:space-between"><strong>{{ YearEndService::yearName($hs, $he) }}</strong><span>{{ Money::cmp($h->profit, 0) < 0 ? 'Loss' : 'Profit' }} {{ Money::format(ltrim((string) $h->profit, '-')) }}</span></div>
                <div class="muted" style="font-size:12px">Closed {{ Money::date($h->closed_at) }}{{ $h->closed_by !== '' ? ' by '.$h->closed_by : '' }}@if ($h->reopened_at) · reopened {{ Money::date($h->reopened_at) }}@endif</div>
                @if ($last && (int) $last->id === (int) $h->id)
                    <form method="POST" action="{{ route('settings.year-end.reopen') }}" style="margin-top:6px" onsubmit="return confirm('Reopen the {{ YearEndService::yearName($hs, $he) }} financial year? It becomes the current year again and its dates can be changed.')">@csrf<button class="ghost sm">Reopen</button></form>
                @endif
            </div>
        @empty
            <p class="muted">No year has been closed yet.</p>
        @endforelse
        <p class="muted" style="font-size:12px">To stop changes to a month that is finished, use <a href="{{ route('settings.close-books') }}">Close books</a>.</p>
    </div>
</div>
@endsection
