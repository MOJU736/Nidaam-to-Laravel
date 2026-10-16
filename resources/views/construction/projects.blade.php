@extends('layouts.main')
@section('title', 'Projects')
@section('content')
@include('construction._tabs')
@php use App\Support\Money; use App\Services\Construction\ConstructionService as C; @endphp
<div class="bar"><div><h1>Projects</h1><div class="muted">Contract, costs and what the client still owes, project by project</div></div><a class="btn" href="{{ route('projects.create') }}">{{ \App\Support\Icon::svg('plus', 16) }}New project</a></div>
<div class="tiles">
    <div class="tile"><small>Contract value</small><b>{{ Money::format($totals['contract']) }}</b><span>{{ count($rows) }} project{{ count($rows) === 1 ? '' : 's' }} shown</span></div>
    <div class="tile"><small>Costs so far</small><b>{{ Money::format($totals['cost']) }}</b><span>billed {{ Money::format($totals['billed']) }}</span></div>
    <div class="tile {{ Money::cmp($totals['profit'], 0) < 0 ? 'bad' : '' }}"><small>Profit</small><b>{{ Money::format($totals['profit']) }}</b><span>billed minus costs</span></div>
    <div class="tile"><small>Clients owe</small><b>{{ Money::format($totals['owed']) }}</b><span>unpaid on claims</span></div>
</div>
<div class="tools"><div class="chips">
    <a class="chip {{ $status ? '' : 'on' }}" href="{{ route('projects.index') }}">All <small>{{ $counts[''] ?? 0 }}</small></a>
    @foreach (C::STATUSES as $k => $l)<a class="chip {{ $status === $k ? 'on' : '' }}" href="{{ route('projects.index', ['status' => $k]) }}">{{ $l }} <small>{{ $counts[$k] ?? 0 }}</small></a>@endforeach
</div></div><div class="card tbl">
    <table>
        <thead><tr><th>Project</th><th>Client</th><th>Status</th><th class="num">Contract</th><th class="num">Costs</th><th class="num">Billed</th><th class="num">Profit</th><th class="num">Client owes</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td><a class="who" href="{{ route('projects.show', $r->id) }}"><span class="avs sq">{{ mb_strtoupper(mb_substr(trim($r->name), 0, 1)) }}</span><span><strong>#{{ $r->number }} {{ $r->name }}</strong>@if ($r->site)<small class="muted" style="display:block;font-size:12.5px">{{ $r->site }}</small>@endif</span></a></td>
                <td>{{ $r->customer_name }}</td>
                <td><span class="pill">{{ C::STATUSES[$r->status] ?? $r->status }}</span></td>
                <td class="num">{{ Money::format($r->contract) }}</td>
                <td class="num">{{ Money::format($r->cost) }}@if ($r->budget_used_pct !== null)<div class="muted" style="font-size:12px">{{ $r->budget_used_pct }}% of budget</div>@endif</td>
                <td class="num">{{ Money::format($r->billed) }}@if ($r->billed_pct !== null)<div class="muted" style="font-size:12px">{{ $r->billed_pct }}% of contract</div>@endif</td>
                <td class="num" @if (Money::cmp($r->profit, 0) < 0) style="color:var(--danger)" @endif>{{ Money::format($r->profit) }}</td>
                <td class="num">{{ Money::format($r->owed) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted" style="text-align:center;padding:24px">No projects yet. <a href="{{ route('projects.create') }}">Create the first one</a>.</td></tr>
        @endforelse
        </tbody>
        @if (count($rows) > 1)<tfoot><tr><th colspan="3">Total</th><th class="num">{{ Money::format($totals['contract']) }}</th><th class="num">{{ Money::format($totals['cost']) }}</th><th class="num">{{ Money::format($totals['billed']) }}</th><th class="num">{{ Money::format($totals['profit']) }}</th><th class="num">{{ Money::format($totals['owed']) }}</th></tr></tfoot>@endif
    </table>
</div>
@endsection
