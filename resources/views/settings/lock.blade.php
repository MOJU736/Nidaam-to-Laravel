@extends('layouts.main')
@section('title', 'Lock company')
@section('content')
@include('settings._tabs')
<div class="bar"><h1>Lock company</h1></div>
<div class="card" style="max-width:720px">
    @if ($locked)
        <div class="flash bad">This company is <strong>locked</strong>. Nothing can be added, changed, voided or deleted until it is unlocked.</div>
    @else
        <div class="flash" style="border:1px solid var(--line)">This company is open for changes.</div>
    @endif
    <p style="margin-top:0">Locking stops <em>every</em> change in this company, whatever the date: documents, payments, journal entries, accounts and settings. Use it when the books are handed to an auditor, or the company is being wound down. To protect only a finished period, use <a href="{{ route('settings.close-books') }}">Close books</a> instead.</p>
    <form method="POST" action="{{ route('settings.lock') }}" onsubmit="return confirm('{{ $locked ? 'Unlock this company?' : 'Lock this company? Nothing can be changed until it is unlocked here.' }}')">
        @csrf @method('PUT')<input type="hidden" name="locked" value="{{ $locked ? 0 : 1 }}">
        <button class="{{ $locked ? '' : 'danger' }}">{{ $locked ? 'Unlock company' : 'Lock company' }}</button>
    </form>
</div>
@endsection
