@extends('layouts.main')
@section('title', 'Projects')
@section('content')
@include('construction._tabs')
@php use App\Support\Money; use App\Services\Construction\ConstructionService as C; @endphp
<div class="bar"><h1 style="margin:0">Projects</h1><a class="btn" href="{{ route('projects.create') }}">New project</a></div>
<div class="actions" style="margin-bottom:12px">
    <a class="btn sm {{ $status ? 'ghost' : '' }}" href="{{ route('projects.index') }}">All ({{ $counts[''] ?? 0 }})</a>
    @foreach (C::STATUSES as $k => $l)<a class="btn sm {{ $status === $k ? '' : 'ghost' }}" href="{{ route('projects.index', ['status' => $k]) }}">{{ $l }} ({{ $counts[$k] ?? 0 }})</a>@endforeach
</div>
<div class="card tbl">
    <table>
        <thead><tr><th>Project</th><th>Client</th><th>Status</th><th class="num">Contract</th><th class="num">Costs</th><th class="num">Billed</th><th class="num">Profit</th><th class="num">Client owes</th></tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr>
                <td><a href="{{ route('projects.show', $r->id) }}"><strong>#{{ $r->number }} {{ $r->name }}</strong></a>@if ($r->site)<div class="muted" style="font-size:12px">{{ $r->site }}</div>@endif</td>
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
