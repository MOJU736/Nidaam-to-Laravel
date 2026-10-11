@extends('layouts.main')
@section('title', 'Advances & loans')
@section('content')
@php use App\Support\Money; @endphp
@include('payroll._tabs', ['active' => 'advances'])
<div class="card" style="margin-bottom:16px">
    <h1 style="margin:0 0 8px">Give an advance or loan</h1>
    @if (! $employees)
        <p class="muted" style="margin:0">Add an employee first.</p>
    @elseif (! $banks)
        <p class="muted" style="margin:0">There is no cash or bank account yet. Make one under Accounts.</p>
    @else
        <form method="POST" action="{{ route('payroll.advances.store') }}">@csrf
            <div class="row2">
                <div><label for="employee_id">Employee</label><select id="employee_id" name="employee_id" required>@foreach ($employees as $id => $name)<option value="{{ $id }}" @selected((string) old('employee_id', $pick) === (string) $id)>{{ $name }}</option>@endforeach</select></div>
                <div><label for="kind">Kind</label><select id="kind" name="kind"><option value="advance" @selected(old('kind') !== 'loan')>Salary advance</option><option value="loan" @selected(old('kind') === 'loan')>Loan</option></select></div>
                <div><label for="amount">Amount given</label><input id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" required></div>
                <div><label for="monthly">Take back each month</label><input id="monthly" name="monthly" inputmode="decimal" value="{{ old('monthly') }}" placeholder="all at once if empty (advances)"></div>
                <div><label for="date">Date</label><input id="date" name="date" type="date" value="{{ old('date', date('Y-m-d')) }}" required></div>
                <div><label for="ledger_id">Paid from</label><select id="ledger_id" name="ledger_id" required>@foreach ($banks as $id => $name)<option value="{{ $id }}" @selected((string) old('ledger_id') === (string) $id)>{{ $name }}</option>@endforeach</select></div>
            </div>
            <label for="notes">Notes</label><input id="notes" name="notes" maxlength="255" value="{{ old('notes') }}">
            <div class="actions" style="margin-top:12px"><button>Save</button></div>
        </form>
    @endif
</div>
<div class="card">
    @if ($advances->isEmpty())<p class="muted" style="margin:0">No advances or loans yet.</p>@else
    <div class="tbl"><table>
        <thead><tr><th>Date</th><th>Employee</th><th>Kind</th><th class="num">Amount</th><th class="num">Each month</th><th class="num">Taken back</th><th class="num">Still owed</th><th>Status</th><th></th></tr></thead>
        <tbody>@foreach ($advances as $a)
            <tr @style(['opacity:.55' => $a->status === 'void'])>
                <td>{{ Money::date($a->date) }}</td><td><a href="{{ route('payroll.employees.show', $a->employee_id) }}">{{ $a->employee_name }}</a><span class="psub">{{ $a->ledger_name }}{{ $a->notes ? ' · '.$a->notes : '' }}</span></td>
                <td>{{ $a->kind === 'loan' ? 'Loan' : 'Advance' }}</td><td class="num">{{ Money::format($a->amount) }}</td><td class="num">{{ Money::positive($a->monthly) ? Money::format($a->monthly) : '' }}</td>
                <td class="num">{{ Money::format($a->repaid) }}</td><td class="num">{{ $a->status === 'void' ? '' : Money::format($pay->balanceOf($a)) }}</td><td>{{ ucfirst($a->status) }}</td>
                <td class="num">@if ($a->status !== 'void' && ! Money::positive($a->repaid))
                    <form method="POST" action="{{ route('payroll.advances.void', $a->id) }}" class="inline" onsubmit="var r = prompt('Void this {{ $a->kind }}? Its entry is removed. Reason (optional):'); if (r === null) return false; this.void_reason.value = r;">@csrf<input type="hidden" name="void_reason"><button class="ghost sm danger">Void</button></form>
                @endif</td>
            </tr>
        @endforeach</tbody>
    </table></div>@endif
</div>
@endsection
