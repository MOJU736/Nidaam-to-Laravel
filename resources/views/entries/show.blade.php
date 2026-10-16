@extends('layouts.main')
@section('title', $typeName.' '.$number)
@section('content')
@php use App\Support\Money; @endphp
<a class="back" href="{{ route('entries.index') }}">&larr; Journal Entries</a>
<div class="bar">
    <div class="titlerow"><h1>{{ $typeName }} {{ $number }}</h1>@if ($tag)<span class="pill owes">{{ $tag->title }}</span>@endif</div>
    <div class="actions">
        @if ($linked)
            <span class="muted" style="align-self:center">Made by {{ preg_match('/^[aeiou]/i', $linked['label']) ? 'an' : 'a' }} {{ $linked['label'] }} - change it there.</span>
        @elseif ($locked)
            <span class="muted" style="align-self:center">Locked: the books are closed for this date.</span>
        @else
            <a class="btn ghost" href="{{ route('entries.edit', [$label, $entry->id]) }}">Edit</a>
            <form class="inline" method="POST" action="{{ route('entries.destroy', [$label, $entry->id]) }}" onsubmit="return confirm('Delete this entry?')">@csrf @method('DELETE')<button class="danger">Delete</button></form>
        @endif
    </div>
</div>

<div class="docgrid">
    <div class="card docmeta">
        <div><small>Date</small>{{ Money::date($entry->date) }}</div>
        <div><small>Type</small>{{ $typeName }}</div>
        @if ($entry->notes)<div class="wide2"><small>Notes</small>{!! nl2br(e($entry->notes)) !!}</div>@endif
    </div>
    <div class="card balance">
        <small>Amount</small>
        <div class="big">{{ Money::format($entry->dr_total) }}</div>
        <dl><dt>Debits</dt><dd>{{ Money::format($entry->dr_total) }}</dd><dt>Credits</dt><dd>{{ Money::format($entry->cr_total) }}</dd></dl>
    </div>
</div>

<div class="card tbl">
    <table>
        <thead><tr><th>Account</th><th>Description</th>@if ($lines->contains(fn ($l) => $l['project']))<th>Project</th>@endif<th class="num">Debit</th><th class="num">Credit</th></tr></thead>
        <tbody>
        @foreach ($lines as $l)
            <tr>
                <td>{{ $l['account'] }}</td><td>{{ $l['narration'] }}</td>
                @if ($lines->contains(fn ($x) => $x['project']))<td>{{ $l['project'] }}</td>@endif
                <td class="num">{{ $l['dc'] === 'D' ? Money::format($l['amount']) : '' }}</td>
                <td class="num">{{ $l['dc'] === 'C' ? Money::format($l['amount']) : '' }}</td>
            </tr>
        @endforeach
            <tr><td colspan="{{ $lines->contains(fn ($x) => $x['project']) ? 3 : 2 }}"><strong>Total</strong></td><td class="num"><strong>{{ Money::format($entry->dr_total) }}</strong></td><td class="num"><strong>{{ Money::format($entry->cr_total) }}</strong></td></tr>
        </tbody>
    </table>
</div>
@endsection
