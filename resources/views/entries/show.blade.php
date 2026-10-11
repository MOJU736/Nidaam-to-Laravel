@extends('layouts.main')
@section('title', $typeName.' '.$number)
@section('content')
@php use App\Support\Money; @endphp
<div class="bar">
    <div>
        <h1 style="margin:0">{{ $typeName }} {{ $number }}</h1>
        <a class="muted" href="{{ route('entries.index') }}">&larr; Journal Entries</a>
    </div>
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

<div class="card">
    <dl class="kv">
        <dt>Date</dt><dd>{{ Money::date($entry->date) }}</dd>
        <dt>Type</dt><dd>{{ $typeName }}</dd>
        @if ($tag)<dt>Tag</dt><dd><span class="pill">{{ $tag->title }}</span></dd>@endif
        @if ($entry->notes)<dt>Notes</dt><dd>{!! nl2br(e($entry->notes)) !!}</dd>@endif
    </dl>
</div>

<div class="card tbl" style="margin-top:16px">
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
