@extends('layouts.main')
@section('title', $project ? 'Edit project' : 'New project')
@section('content')
@include('construction._tabs')
@php
    use App\Services\Construction\ConstructionService as C;
    $v = fn ($k, $d = '') => old($k, $project->{$k} ?? $d);
    $num = fn ($x) => $x === null || $x === '' ? '' : rtrim(rtrim((string) $x, '0'), '.');
@endphp
<a class="back" href="{{ $project ? route('projects.show', $project->id) : route('projects.index') }}">&larr; Back</a>
<div class="bar"><h1>{{ $project ? 'Edit project #'.$project->number : 'New project #'.$next }}</h1></div>
<form class="card" method="POST" action="{{ $project ? route('projects.update', $project->id) : route('projects.store') }}" style="max-width:800px">
    @csrf @if ($project) @method('PUT') @endif
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <div class="row2">
        <div><label for="name" style="margin-top:0">Project name</label><input id="name" name="name" maxlength="255" required value="{{ $v('name') }}"></div>
        <div><label for="customer_id" style="margin-top:0">Client (customer)</label>
            <select id="customer_id" name="customer_id" required><option value="">Choose...</option>@foreach ($customers as $id => $n)<option value="{{ $id }}" @selected((string) $v('customer_id', request('customer')) === (string) $id)>{{ $n }}</option>@endforeach</select></div>
    </div>
    <label for="site">Site / location <span class="muted">(optional)</span></label><input id="site" name="site" maxlength="255" value="{{ $v('site') }}">
    <div class="row2">
        <div><label for="contract_amount">Contract amount</label><input id="contract_amount" name="contract_amount" inputmode="decimal" value="{{ $v('contract_amount') !== '' && (float) $v('contract_amount') == 0 ? '' : $v('contract_amount') }}" placeholder="0.00"></div>
        <div><label for="status">Status</label><select id="status" name="status">@foreach (C::STATUSES as $k => $l)<option value="{{ $k }}" @selected($v('status', 'active') === $k)>{{ $l }}</option>@endforeach</select></div>
    </div>
    <div class="row2">
        <div><label for="start_date">Start date</label><input id="start_date" type="date" name="start_date" value="{{ substr((string) $v('start_date', now()->toDateString()), 0, 10) }}"></div>
        <div><label for="end_date">End date</label><input id="end_date" type="date" name="end_date" value="{{ substr((string) $v('end_date'), 0, 10) }}"></div>
    </div>
    <div class="row2">
        <div><label for="retention_percent">Retention % held back on each claim</label><input id="retention_percent" name="retention_percent" inputmode="decimal" value="{{ $num($v('retention_percent', '0')) ?: '0' }}"></div>
        <div><label for="advance_amount">Client advance agreed</label><input id="advance_amount" name="advance_amount" inputmode="decimal" value="{{ $v('advance_amount') !== '' && (float) $v('advance_amount') == 0 ? '' : $v('advance_amount') }}" placeholder="0.00"></div>
    </div>
    <h2>Budget by cost type</h2>
    <div class="row2">
        @foreach (C::COST_TYPES as $k => $l)<div><label for="budget_{{ $k }}">{{ $l }}</label><input id="budget_{{ $k }}" name="budget_{{ $k }}" inputmode="decimal" placeholder="0.00" value="{{ old("budget_$k", ((float) ($budgets[$k] ?? 0)) == 0 ? '' : $budgets[$k]) }}"></div>@endforeach
    </div>
    <label for="notes">Notes</label><textarea id="notes" name="notes" rows="3" maxlength="1000">{{ $v('notes') }}</textarea>
    <div class="actions" style="margin-top:20px"><button>{{ $project ? 'Save project' : 'Create project' }}</button></div>
</form>
@endsection
