@extends('layouts.main')
@section('title', $ui['names'])
@section('content')
@php use App\Support\Money; @endphp
@include('credits._tabs')
<div class="bar"><h1 style="margin:0">{{ $ui['names'] }}</h1><a class="btn" href="{{ route($ui['route'].'.create') }}">+ New {{ strtolower($ui['name']) }}</a></div>
<p class="muted" style="margin-top:0">{{ $ui['side'] === 'ar' ? 'Money given back to a customer: returned goods, a price correction or goodwill. A bad-debt write-off also shows here (WO-).' : 'Money a supplier gives back: goods returned to them, or an overcharge.' }}</p>
<div class="card tbl"><table>
    <thead><tr><th>No.</th><th>Date</th><th>{{ ucfirst($ui['party_word']) }}</th><th>Status</th><th class="num">Total</th><th class="num">Left to use</th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
        <tr><td><a href="{{ route($ui['route'].'.show', $r->id) }}">{{ $r->label }}</a></td><td>{{ Money::date($r->date) }}</td><td>{{ $r->party_name }}</td>
            <td><span class="pill {{ $r->status === 'void' ? 'overdue' : '' }}">{{ ucfirst($r->status) }}</span></td><td class="num">{{ Money::format($r->total) }}</td>
            <td class="num">{{ $r->remaining !== null ? Money::format($r->remaining) : '' }}</td></tr>
    @empty
        <tr><td colspan="6" class="muted" style="text-align:center;padding:24px">None yet.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
