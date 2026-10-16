@extends('layouts.main')
@section('title', 'Employees')
@section('content')
@php use App\Support\Money; $e = $edit; $v = fn ($k, $d = '') => old($k, $e->$k ?? $d); @endphp
@include('payroll._tabs', ['active' => 'employees'])
<div class="bar"><div><h1>Employees</h1><div class="muted">{{ count($employees) }} on the books</div></div></div>
<details class="sec" @if ($e || $errors->any() || $employees->isEmpty()) open @endif>
    <summary><span class="n">+</span> {{ $e ? 'Change '.$e->name : 'Add an employee' }}</summary>
    <div>
    <form method="POST" action="{{ $e ? route('payroll.employees.update', $e->id) : route('payroll.employees.store') }}">@csrf @if ($e) @method('PUT') @endif
        <div class="row2">
            <div><label for="name">Name</label><input id="name" name="name" maxlength="150" value="{{ $v('name') }}" required></div>
            <div><label for="salary">Monthly salary</label><input id="salary" name="salary" inputmode="decimal" value="{{ $v('salary') !== '' ? number_format((float) $v('salary'), 2, '.', '') : '' }}" required></div>
            <div><label for="position">Job title</label><input id="position" name="position" maxlength="100" value="{{ $v('position') }}"></div>
            <div><label for="department">Department</label><input id="department" name="department" maxlength="100" list="deps" value="{{ $v('department') }}"><datalist id="deps">@foreach ($departments as $d)<option value="{{ $d }}">@endforeach</datalist></div>
            <div><label for="phone">Phone (WhatsApp)</label><input id="phone" name="phone" maxlength="50" value="{{ $v('phone') }}"></div>
            <div><label for="email">Email</label><input id="email" name="email" type="email" maxlength="150" value="{{ $v('email') }}"></div>
            <div><label for="method">Paid by</label><select id="method" name="method">@foreach ($methods as $k => $label)<option value="{{ $k }}" @selected($v('method', 'cash') === $k)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="pay_account">Account / mobile number</label><input id="pay_account" name="pay_account" maxlength="100" value="{{ $v('pay_account') }}"></div>
            @if ($projects)
                <div><label for="project_id">Project</label><select id="project_id" name="project_id"><option value="">No project</option>@foreach ($projects as $id => $name)<option value="{{ $id }}" @selected((string) $v('project_id') === (string) $id)>{{ $name }}</option>@endforeach</select></div>
            @endif
            <div><label for="start_date">Start date</label><input id="start_date" name="start_date" type="date" value="{{ $v('start_date') ? substr($v('start_date'), 0, 10) : '' }}"></div>
            <div><label for="status">Status</label><select id="status" name="status"><option value="active" @selected($v('status', 'active') === 'active')>Active</option><option value="inactive" @selected($v('status') === 'inactive')>Inactive (left the company)</option></select></div>
        </div>
        <label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" maxlength="500">{{ $v('notes') }}</textarea>
        <div class="actions" style="margin-top:12px"><button>{{ $e ? 'Save changes' : 'Add employee' }}</button>@if ($e)<a class="btn ghost" href="{{ route('payroll.employees') }}">Cancel</a>@endif</div>
    </form>
    </div>
</details>
<div class="card">
    @if ($employees->isEmpty())
        <p class="muted" style="margin:0">No employees yet.</p>
    @else
        <div class="tbl"><table>
            <thead><tr><th>Name</th><th>Job / department</th><th>Paid by</th><th class="num">Salary</th><th class="num">Owes</th><th></th></tr></thead>
            <tbody>
            @foreach ($employees as $x)
                <tr @style(['opacity:.55' => $x->status !== 'active'])>
                    <td><a class="who" href="{{ route('payroll.employees.show', $x->id) }}"><span class="avs">{{ mb_strtoupper(mb_substr(trim($x->name), 0, 1)) }}</span><span><strong>{{ $x->name }}</strong>@if ($x->status !== 'active') <span class="pill">inactive</span>@endif<span class="psub">{{ $x->phone }}{{ $x->phone && $x->email ? ' · ' : '' }}{{ $x->email }}</span></span></a></td>
                    <td>{{ $x->position }}<span class="psub">{{ $x->department }}</span></td>
                    <td>{{ $methods[$x->method] ?? '' }}<span class="psub">{{ $x->pay_account }}</span></td>
                    <td class="num">{{ Money::format($x->salary) }}</td>
                    <td class="num">{{ Money::positive($owes[$x->id]) ? Money::format($owes[$x->id]) : '' }}</td>
                    <td class="num"><a class="btn ghost sm" href="{{ route('payroll.employees', ['edit' => $x->id]) }}">Edit</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
</div>
@endsection
