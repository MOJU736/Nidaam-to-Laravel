{{-- The tabs of one project. $p = the project, $on = which is open. --}}
@php $pt = [['overview', 'Overview', route('projects.show', $p->id)], ['estimate', 'Estimate', route('projects.estimate', $p->id)], ['schedule', 'Schedule', route('projects.schedule', $p->id)], ['reports', 'Site reports', route('projects.reports', $p->id)]]; @endphp
<a class="back" href="{{ route('projects.index') }}">&larr; Projects</a>
<div class="titlerow" style="margin-bottom:10px"><span class="avs sq lg">#{{ $p->number }}</span><h2 style="margin:0;font-size:22px">{{ $p->name }}</h2></div>
<nav class="subnav" aria-label="Project sections">
    @foreach ($pt as [$k, $label, $url])<a class="{{ $on === $k ? 'on' : '' }}" href="{{ $url }}">{{ $label }}</a>@endforeach
</nav>