@extends('layouts.main')
@section('title', $e->name)
@section('content')
@php use App\Support\Money; @endphp
@include('payroll._tabs', ['active' => 'employees'])
<div class="bar">
    <div><h1>{{ $e->name }}@if ($e->status !== 'active') <span class="pill">inactive</span>@endif</h1>
        <div class="muted">{{ $e->position }}{{ $e->position && $e->department ? ' · ' : '' }}{{ $e->department }}</div></div>
    <div class="actions"><a class="btn ghost" href="{{ route('payroll.employees', ['edit' => $e->id]) }}">Edit</a><a class="btn ghost" href="{{ route('payroll.advances', ['employee' => $e->id]) }}">Give an advance or loan</a></div>
</div>
<div class="grid" style="margin-bottom:16px">
    <div class="stat"><b>{{ Money::format($e->salary) }}</b><span class="muted">monthly salary</span></div>
    <div class="stat"><b>{{ $pay::METHODS[$e->method] ?? '' }}</b><span class="muted">{{ $e->pay_account ?: 'paid by' }}</span></div>
    <div class="stat"><b @style(['color:var(--danger)' => Money::positive($owes)])>{{ Money::format($owes) }}</b><span class="muted">owed in advances &amp; loans</span></div>
    @if ($e->project_id && isset($projects[$e->project_id]))<div class="stat"><b style="font-size:16px">{{ $projects[$e->project_id] }}</b><span class="muted">project</span></div>@endif
</div>
<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">Pay history</h2>
    @if ($history->isEmpty())<p class="muted" style="margin:0">Nothing paid yet - only approved pay runs show here.</p>@else
    <div class="tbl"><table>
        <thead><tr><th>Month</th><th class="num">Gross</th><th class="num">Taken back</th><th class="num">Net</th><th class="num">Paid</th><th></th></tr></thead>
        <tbody>@foreach ($history as $h)
            <tr><td><a href="{{ route('payroll.runs.show', $h->run_id) }}">{{ $pay->monthLabel($h->period) }}</a></td><td class="num">{{ Money::format($h->gross) }}</td><td class="num">{{ Money::positive($h->repay) ? Money::format($h->repay) : '' }}</td>
                <td class="num">{{ Money::format($h->net) }}</td><td class="num">{{ Money::format($h->paid) }}</td><td class="num"><a class="btn ghost sm" href="{{ route('payroll.payslip', $h->id) }}">Payslip</a></td></tr>
        @endforeach</tbody>
    </table></div>@endif
</div>
<div class="card">
    <h2 style="margin-top:0">Advances &amp; loans</h2>
    @if ($advances->isEmpty())<p class="muted" style="margin:0">None.</p>@else
    <div class="tbl"><table>
        <thead><tr><th>Date</th><th>Kind</th><th class="num">Amount</th><th class="num">Each month</th><th class="num">Taken back</th><th class="num">Still owed</th><th>Status</th></tr></thead>
        <tbody>@foreach ($advances as $a)
            <tr @style(['opacity:.55' => $a->status === 'void'])><td>{{ Money::date($a->date) }}</td><td>{{ $a->kind === 'loan' ? 'Loan' : 'Advance' }}</td><td class="num">{{ Money::format($a->amount) }}</td><td class="num">{{ Money::positive($a->monthly) ? Money::format($a->monthly) : '' }}</td>
                <td class="num">{{ Money::format($a->repaid) }}</td><td class="num">{{ $a->status === 'void' ? '' : Money::format($pay->balanceOf($a)) }}</td><td>{{ ucfirst($a->status) }}</td></tr>
        @endforeach</tbody>
    </table></div>@endif
</div>
@endsection
