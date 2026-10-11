@extends('layouts.main')
@section('title', 'Dashboard')
@section('content')
<div class="bar">
    <div>
        <h1 style="margin:0">{{ $company?->name ?? 'Company' }}</h1>
        <span class="muted">{{ auth()->user()->displayName() }}</span>
    </div>
</div>
<div class="grid">
    @foreach ($counts as $label => $n)
        <div class="stat"><b>{{ number_format($n) }}</b><span class="muted">{{ $label }}</span></div>
    @endforeach
</div>
@endsection