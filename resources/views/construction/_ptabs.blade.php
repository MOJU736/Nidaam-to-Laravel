{{-- The tabs of one project. $p = the project, $on = which is open. --}}
@php $pt = [['overview', 'Overview', route('projects.show', $p->id)], ['estimate', 'Estimate', route('projects.estimate', $p->id)], ['schedule', 'Schedule', route('projects.schedule', $p->id)], ['reports', 'Site reports', route('projects.reports', $p->id)]]; @endphp
<div class="actions" style="margin-bottom:14px">
    <strong style="margin-right:6px">#{{ $p->number }} {{ $p->name }}</strong>
    @foreach ($pt as [$k, $label, $url])<a class="btn sm {{ $on === $k ? '' : 'ghost' }}" href="{{ $url }}">{{ $label }}</a>@endforeach
</div>
