@extends('layouts.main')
@section('title', 'Wage sheets')
@section('content')
@include('construction._tabs')
@php use App\Support\Money; @endphp
<div class="bar"><h1 style="margin:0">Wage sheets</h1><a class="btn" href="{{ route('construction.wages.create') }}">New wage sheet</a></div>
<div class="card tbl">
    <table>
        <thead><tr><th>Sheet</th><th>Period</th><th>Date</th><th>Status</th><th class="num">Total</th></tr></thead>
        <tbody>
        @forelse ($sheets as $s)
            <tr style="{{ $s->status === 'void' ? 'text-decoration:line-through;opacity:.6' : '' }}"><td><a href="{{ route('construction.wages.show', $s->id) }}">Wage sheet {{ $s->number }}</a></td><td>{{ Money::date($s->date_from) }} – {{ Money::date($s->date_to) }}</td><td>{{ Money::date($s->date) }}</td>
                <td><span class="pill">{{ ['paid' => 'Paid', 'unpaid' => 'Not paid yet', 'void' => 'Void'][$s->status] ?? $s->status }}</span></td><td class="num">{{ Money::format($s->total) }}</td></tr>
        @empty
            <tr><td colspan="5" class="muted" style="text-align:center;padding:20px">No wage sheets yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
