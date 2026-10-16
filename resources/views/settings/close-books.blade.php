@extends('layouts.main')
@section('title', 'Close books')
@section('content')
@include('settings._tabs')
<div class="bar"><h1>Close books</h1></div>
<div class="card" style="max-width:720px">
    @if ($lock)
        <div class="flash ok">The books are closed through <strong>{{ \App\Support\Money::date($lock) }}</strong>. Nothing dated on or before then can be added, changed, voided or deleted.</div>
    @else
        <div class="flash" style="border:1px solid var(--line)">The books are open: any date can be changed.</div>
    @endif
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <p style="margin-top:0">Once a month or year is finished and reported (tax filed, statements sent, accounts checked), close it so the numbers cannot change by accident. After you close through a date, nobody can add, edit, void or delete anything dated on or before it: invoices, bills, payments, credit notes, write-offs, stock adjustments, payroll and journal entries all refuse with a clear message.</p>
    <p class="muted" style="font-size:13px">Drafts can still be edited (they are not in the books yet) but cannot be issued with a closed date. Every change here is recorded in the activity log.</p>
    <form method="POST" action="{{ route('settings.close-books') }}" onsubmit="return confirm('Close the books through this date? Nothing on or before it can be changed until the books are reopened here.')">
        @csrf @method('PUT')
        <div class="actions" style="align-items:end">
            <div><label for="lock_date" style="margin-top:0">Close the books through</label><input id="lock_date" type="date" name="lock_date" required max="{{ now()->toDateString() }}" value="{{ old('lock_date', $lock ?: now()->subMonthNoOverflow()->endOfMonth()->toDateString()) }}"></div>
            <button>{{ $lock ? 'Change date' : 'Close books' }}</button>
        </div>
    </form>
    @if ($lock)
        <form method="POST" action="{{ route('settings.close-books') }}" style="margin-top:16px" onsubmit="return confirm('Reopen the books? Every date will be changeable again.')">
            @csrf @method('PUT')<input type="hidden" name="reopen" value="1">
            <button class="danger sm">Reopen the books</button> <span class="muted" style="font-size:13px">or set an earlier date above to reopen only part of the period.</span>
        </form>
    @endif
</div>
@endsection
