@extends('layouts.main')
@section('title', 'Site reports - '.$p->name)
@section('content')
@include('construction._tabs')
@include('construction._ptabs', ['p' => $p, 'on' => 'reports'])
@php use App\Support\Money; @endphp
@foreach ($errors->all() as $er)<div class="flash bad">{{ $er }}</div>@endforeach
<div class="bar"><h1 style="margin:0">Weekly site reports</h1></div>
<form class="card" method="POST" action="{{ route('projects.reports.store', $p->id) }}" style="margin-bottom:14px;max-width:620px">
    @csrf <div class="actions" style="align-items:end"><div><label style="margin-top:0">Week containing</label><input type="date" name="week" value="{{ now()->toDateString() }}"></div><button>New report</button></div>
    <p class="muted" style="font-size:13px;margin:6px 0 0">Progress, manpower, materials used and the week's costs fill in by themselves from the schedule, wage sheets, site stock and the books.</p>
</form>
<div class="card tbl">
    <table>
        <thead><tr><th>Report</th><th>Week</th><th>Status</th><th class="num">Planned</th><th class="num">Done</th><th>Prepared by</th></tr></thead>
        <tbody>
        @forelse ($reports as $r)
            <tr><td><a href="{{ route('projects.report', $r->id) }}">Report {{ $r->number }}</a></td><td>{{ Money::date($r->week_start) }} – {{ Money::date($r->week_end) }}</td><td><span class="pill">{{ $statuses[$r->status] ?? $r->status }}</span></td>
                <td class="num">{{ $r->planned_pct !== null ? (float) $r->planned_pct.'%' : '' }}</td><td class="num">{{ $r->actual_pct !== null ? (float) $r->actual_pct.'%' : '' }}</td><td>{{ $r->prepared_by }}</td></tr>
        @empty
            <tr><td colspan="6" class="muted" style="text-align:center;padding:20px">No site reports yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
