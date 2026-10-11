@extends('layouts.main')
@section('title', 'Books Health Check')
@section('content')
@php use App\Support\Money; $levels = ['problem' => ['Needs fixing', 'var(--danger)'], 'warning' => ['Worth a look', '#b7791f'], 'info' => ['For your information', 'var(--muted)']]; @endphp
<div class="bar"><div><h1 style="margin:0">Books Health Check</h1><div class="muted">Looks for what makes a report look wrong, and says what to do. Checked {{ $checkedAt }}.</div></div><a class="btn ghost" href="{{ route('reports.index') }}">All reports</a></div>
@if (! $findings)<div class="card" style="color:#1d9650"><strong>✓ The books look healthy.</strong> <span class="muted">Nothing to fix or look at.</span></div>@endif
@foreach (['problem', 'warning', 'info'] as $lv)
    @php $list = array_values(array_filter($findings, fn ($f) => $f['level'] === $lv)); @endphp
    @if ($list)
        <h2 style="color:{{ $levels[$lv][1] }}">{{ $levels[$lv][0] }} ({{ count($list) }})</h2>
        @foreach ($list as $f)
            <div class="card" style="margin-bottom:12px;border-left:4px solid {{ $levels[$lv][1] }}">
                <strong>{{ $f['title'] }}</strong>
                <p class="muted" style="margin:4px 0 8px">{{ $f['why'] }}</p>
                @if ($f['rows'])
                    <table style="max-width:760px">
                        @foreach ($f['rows'] as [$label, $amount, $url, $note])
                            <tr><td>@if ($url)<a href="{{ $url }}">{{ $label }}</a>@else{{ $label }}@endif</td><td class="num">{{ $amount !== null ? Money::format($amount) : '' }}</td><td class="muted">{{ $note }}</td></tr>
                        @endforeach
                    </table>
                    @if ($f['more'])<div class="muted" style="font-size:13px">…and {{ $f['more'] }} more.</div>@endif
                @endif
                @if ($f['action'] && auth()->user()->isAdmin())
                    <form method="POST" action="{{ $f['action']['url'] }}" style="margin-top:8px" onsubmit="return confirm(@js($f['action']['confirm']))">@csrf<button class="ghost sm">{{ $f['action']['label'] }}</button></form>
                @endif
            </div>
        @endforeach
    @endif
@endforeach
@endsection