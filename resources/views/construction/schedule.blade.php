@extends('layouts.main')
@section('title', 'Schedule - '.$p->name)
@section('content')
@include('construction._tabs')
@include('construction._ptabs', ['p' => $p, 'on' => 'schedule'])
@php use App\Support\Money; $behind = $progress['planned'] !== null && $progress['actual'] < $progress['planned'] - 5; @endphp
@foreach ($errors->all() as $er)<div class="flash bad">{{ $er }}</div>@endforeach
<div class="bar"><h1>Schedule</h1></div>
@if ($progress['planned'] !== null)
<div class="card" style="margin-bottom:14px;max-width:640px">
    <div class="row2">
        <div><div class="muted">Planned today</div><div style="font-size:22px;font-weight:700">{{ $progress['planned'] }}%</div><div style="height:8px;background:var(--line);border-radius:4px"><div style="height:8px;width:{{ min(100, $progress['planned']) }}%;background:#7a8bbf;border-radius:4px"></div></div></div>
        <div><div class="muted">Done</div><div style="font-size:22px;font-weight:700;{{ $behind ? 'color:var(--danger)' : '' }}">{{ $progress['actual'] }}%</div><div style="height:8px;background:var(--line);border-radius:4px"><div style="height:8px;width:{{ min(100, $progress['actual']) }}%;background:{{ $behind ? 'var(--danger)' : 'var(--brand)' }};border-radius:4px"></div></div></div>
    </div>
    <div class="{{ $behind ? '' : 'muted' }}" style="margin-top:8px;{{ $behind ? 'color:var(--danger)' : '' }}">{{ $behind ? 'Behind the plan by '.round($progress['planned'] - $progress['actual'], 1).' points' : ($progress['actual'] > $progress['planned'] + 5 ? 'Ahead of the plan' : 'On plan') }}</div>
</div>
@endif
@if ($stages->isEmpty())
    <div class="card" style="max-width:640px;margin-bottom:14px"><p style="margin-top:0">No stages yet. Start from the 15 standard stages of a building job (spread over the project's dates, with weights), or add your own below.</p>
        <form method="POST" action="{{ route('projects.schedule.standard', $p->id) }}">@csrf<button>Load the 15 standard stages</button></form></div>
@endif
<form class="card tbl" method="POST" action="{{ route('projects.schedule.save', $p->id) }}">
    @csrf @method('PUT')
    <table id="stages">
        <thead><tr><th style="min-width:240px">Stage</th><th>Start</th><th>End</th><th class="num" style="width:90px">Weight</th><th class="num" style="width:90px">% done</th><th>Notes</th><th></th></tr></thead>
        <tbody>
        @php $rows = $stages->isEmpty() ? [(object) []] : $stages; @endphp
        @foreach ($rows as $s)
            <tr><td><input name="name[]" maxlength="255" value="{{ $s->name ?? '' }}"></td><td><input type="date" name="start_date[]" value="{{ substr((string) ($s->start_date ?? ''), 0, 10) }}"></td><td><input type="date" name="end_date[]" value="{{ substr((string) ($s->end_date ?? ''), 0, 10) }}"></td>
                <td><input name="weight[]" inputmode="decimal" class="num" value="{{ isset($s->weight) ? rtrim(rtrim((string) $s->weight, '0'), '.') : '' }}"></td><td><input name="percent_done[]" inputmode="decimal" class="num" value="{{ isset($s->percent_done) ? rtrim(rtrim((string) $s->percent_done, '0'), '.') : '' }}"></td>
                <td><input name="notes[]" maxlength="500" value="{{ $s->notes ?? '' }}"></td><td><button type="button" class="ghost sm rm" title="Remove">&times;</button></td></tr>
        @endforeach
        </tbody>
    </table>
    <div class="actions" style="margin-top:8px"><button type="button" class="ghost sm" id="addStage">+ Add stage</button></div>
    <p class="muted" style="font-size:13px">Weights say how much of the whole job a stage is (they need not add to 100). Planned % comes from the dates; done % is what you enter.</p>
    <div class="actions"><button>Save schedule</button></div>
</form>
<script>
(function () {
    var body = document.querySelector('#stages tbody');
    document.getElementById('addStage').addEventListener('click', function () { var c = body.lastElementChild.cloneNode(true); c.querySelectorAll('input').forEach(function (i) { i.value = ''; }); body.appendChild(c); });
    body.addEventListener('click', function (e) { if (e.target.classList.contains('rm') && body.children.length > 1) { e.target.closest('tr').remove(); } });
})();
</script>
@endsection
