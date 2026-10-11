@extends('layouts.main')
@section('title', 'Stock adjustments')
@section('content')
@php use App\Support\Money; @endphp
@include('items._tabs')
<div class="bar"><h1 style="margin:0">Stock adjustments</h1><a class="btn" href="{{ route('items.adjustments.create') }}">+ New adjustment</a></div>
<div class="card tbl">
    <table>
        <thead><tr><th>No.</th><th>Date</th><th>Reason</th><th class="num">Items</th><th class="num">Value</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($rows as $a)
            <tr>
                <td><a href="{{ route('items.adjustments.show', $a->id) }}">{{ $a->label }}</a></td>
                <td>{{ Money::date($a->date) }}</td>
                <td>{{ $reasons[$a->reason] ?? $a->reason }}@if ($a->notes)<div class="muted" style="font-size:13px">{{ \Illuminate\Support\Str::limit($a->notes, 80) }}</div>@endif</td>
                <td class="num">{{ $a->line_count }}</td>
                <td class="num" @if (Money::cmp($a->total_value, 0) < 0) style="color:var(--danger)" @endif>{{ Money::format($a->total_value) }}</td>
                <td><span class="pill {{ $a->status === 'void' ? 'overdue' : '' }}">{{ $a->status === 'void' ? 'Void' : 'Posted' }}</span></td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted" style="text-align:center;padding:24px">No stock adjustments yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
