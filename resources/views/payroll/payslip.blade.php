@extends('layouts.main')
@section('title', 'Payslip '.$line->name)
@section('content')
@php use App\Support\Money; $l = $line; @endphp
<p class="noprint"><a href="{{ route('payroll.runs.show', $run->id) }}">&larr; {{ $month }} pay run</a></p>
<div class="bar">
    <div><h1>Payslip - {{ $l->name }}</h1><div class="muted">{{ $month }}{{ $l->position ? ' · '.$l->position : '' }}{{ $l->department ? ' · '.$l->department : '' }}</div></div>
    <div class="actions noprint">
        <a class="btn ghost" href="{{ route('payroll.payslip.pdf', $l->id) }}" target="_blank">PDF</a>
        <a class="btn ghost" href="{{ route('payroll.payslip.pdf', ['lineId' => $l->id, 'download' => 1]) }}">Download</a>
        @if ($whatsapp)<a class="btn ghost" href="{{ $whatsapp }}" target="_blank" rel="noopener">WhatsApp</a>@endif
        <button type="button" class="ghost" onclick="window.print()">Print</button>
    </div>
</div>
<div class="card" style="max-width:560px;margin-bottom:16px">
    <table>
        @foreach ($pay->slipRows($l) as [$label, $amount, $kind])
            <tr @style(['font-weight:700' => $kind === 'is-total'])><td>{{ $label }}</td><td class="num">{{ Money::format($amount) }}</td></tr>
        @endforeach
    </table>
    <p class="muted" style="margin-bottom:0">Pay date {{ Money::date($run->date) }} · paid by {{ $methods[$l->method] ?? '' }}{{ $l->pay_account ? ' · '.$l->pay_account : '' }}
        @if (Money::positive($owes)) · still owed in advances and loans: {{ Money::format($owes) }}@endif{{ $l->note ? ' · '.$l->note : '' }}</p>
</div>
<form method="POST" action="{{ route('payroll.payslip.email', $l->id) }}" class="card noprint" style="max-width:560px">@csrf
    <strong>Email this payslip</strong>
    @if ($l->email)
        <p class="muted">It will be sent as a PDF to {{ $l->email }}.</p>
    @else
        <label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" required>
        <label style="font-weight:400"><input type="checkbox" name="save_email" value="1"> Keep this address on the employee</label>
    @endif
    <div class="actions" style="margin-top:12px"><button onclick="return confirm('Email the payslip now?')">Send payslip</button></div>
</form>
@endsection
