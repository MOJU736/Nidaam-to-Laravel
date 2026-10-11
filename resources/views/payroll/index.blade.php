@extends('layouts.main')
@section('title', 'Payroll')
@section('content')
@php use App\Support\Money; $names = ['draft' => 'Draft', 'approved' => 'Approved', 'part' => 'Part paid', 'paid' => 'Paid', 'void' => 'Void']; @endphp
@include('payroll._tabs', ['active' => 'index'])
<div class="grid" style="margin-bottom:16px">
    <div class="stat"><b>{{ $f['employee_count'] }}</b><span class="muted">active employee{{ $f['employee_count'] === 1 ? '' : 's' }}</span></div>
    <div class="stat"><b>{{ Money::format($f['monthly']) }}</b><span class="muted">salaries a month</span></div>
    <div class="stat"><b @style(['color:var(--danger)' => Money::positive($f['unpaid'])])>{{ Money::format($f['unpaid']) }}</b><span class="muted">approved, not paid yet</span></div>
    <div class="stat"><b>{{ Money::format($f['owed']) }}</b><span class="muted">owed in advances &amp; loans</span></div>
</div>
<div class="card">
    <div class="bar">
        <h1 style="margin:0">Pay runs</h1>
        @if ($f['employee_count'])
            <form method="POST" action="{{ route('payroll.runs.store') }}" class="pform">@csrf
                <div><label for="period">Month</label><input type="month" id="period" name="period" value="{{ $f['next'] }}" required></div>
                <div><label for="date">Pay date</label><input type="date" id="date" name="date" value="{{ date('Y-m-t', strtotime($f['next'].'-01')) }}" required></div>
                <div><button>New pay run</button></div>
            </form>
        @endif
    </div>
    @if (! $f['employee_count'])
        <p class="muted">Add your employees and their monthly salaries first. <a class="btn sm" href="{{ route('payroll.employees') }}">Add employees</a></p>
    @elseif ($f['runs']->isEmpty())
        <p class="muted">No pay runs yet. Choose the month above and press New pay run - a draft is made with every active employee.</p>
    @else
        <div class="tbl"><table>
            <thead><tr><th>Month</th><th>Pay date</th><th>Status</th><th class="num">Gross</th><th class="num">Net pay</th></tr></thead>
            <tbody>
            @foreach ($f['runs'] as $r)
                @php $st = $pay->displayStatus($r, (string) ($f['paid_by'][$r->id] ?? '0')); @endphp
                <tr @style(['opacity:.55' => $r->status === 'void'])>
                    <td><a href="{{ route('payroll.runs.show', $r->id) }}"><strong>{{ $pay->monthLabel($r->period) }}</strong></a></td>
                    <td>{{ Money::date($r->date) }}</td>
                    <td><span class="pstat is-{{ $st === 'part' ? 'approved' : $st }}">{{ $names[$st] }}</span></td>
                    <td class="num">{{ Money::format($r->gross) }}</td>
                    <td class="num">{{ Money::format($r->net) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
    <p class="muted" style="margin-bottom:0">A pay run starts as a draft: fill in allowances, overtime, bonus and absent days, then Approve. Approving posts Salaries expense against Salaries payable; paying (everyone at once or one by one, from cash, bank or EVC) clears it. Advances and loans are taken back automatically. Only administrators and roles given Payroll can see this section.</p>
</div>
@endsection
