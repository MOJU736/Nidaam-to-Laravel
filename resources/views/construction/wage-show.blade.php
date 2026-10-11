@extends('layouts.main')
@section('title', 'Wage sheet '.$s->number)
@section('content')
@include('construction._tabs')
@php use App\Support\Money; @endphp
<div class="bar"><h1 style="margin:0">Wage sheet {{ $s->number }} <span class="pill">{{ ['paid' => 'Paid', 'unpaid' => 'Not paid yet', 'void' => 'Void'][$s->status] ?? $s->status }}</span></h1><a class="muted" href="{{ route('construction.wages') }}">&larr; Back</a></div>
@foreach ($errors->all() as $e)<div class="flash bad">{{ $e }}</div>@endforeach
<div class="card" style="margin-bottom:16px">
    <div class="muted">Period {{ Money::date($s->date_from) }} – {{ Money::date($s->date_to) }} · posted {{ Money::date($s->date) }}@if ($s->paid_date) · paid {{ Money::date($s->paid_date) }} from {{ $s->paid_ledger_name }}@endif</div>
    @if ($s->notes)<p>{{ $s->notes }}</p>@endif
    <div class="tbl"><table>
        <thead><tr><th>Worker</th><th>Project</th><th class="num">Days</th><th class="num">Rate</th><th class="num">Amount</th><th>Note</th></tr></thead>
        <tbody>@foreach ($s->lines as $l)<tr><td>{{ $l->worker_name }}</td><td>{{ $l->project_name ? '#'.$l->project_number.' '.$l->project_name : '' }}</td><td class="num">{{ $l->days !== null ? rtrim(rtrim((string) $l->days, '0'), '.') : '' }}</td><td class="num">{{ Money::format($l->rate) }}</td><td class="num">{{ Money::format($l->amount) }}</td><td>{{ $l->note }}</td></tr>@endforeach</tbody>
        <tfoot><tr><th colspan="4">Total</th><th class="num">{{ Money::format($s->total) }}</th><th></th></tr></tfoot>
    </table></div>
</div>
@if ($s->status === 'unpaid')
<form class="card" method="POST" action="{{ route('construction.wages.pay', $s->id) }}" style="margin-bottom:16px">
    @csrf <h2 style="margin-top:0">Pay this sheet</h2>
    <div class="actions" style="align-items:end"><div><label style="margin-top:0">Date</label><input type="date" name="date" required value="{{ now()->toDateString() }}"></div>
        <div><label style="margin-top:0">Paid from</label><select name="ledger_id" required><option value="">Choose...</option>@foreach ($banks as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div><button>Pay {{ Money::format($s->total) }}</button></div>
</form>
@endif
@if ($s->status !== 'void')
<form method="POST" action="{{ route('construction.wages.void', $s->id) }}" onsubmit="return confirm('Void this wage sheet? Its journal entries are removed.')">@csrf<button class="danger sm">Void wage sheet</button></form>
@endif
@endsection
