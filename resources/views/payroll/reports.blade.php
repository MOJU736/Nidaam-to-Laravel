@extends('layouts.main')
@section('title', $r['title'])
@section('content')
@php use App\Support\Money; $company = (string) app(\App\Tenancy\TenantManager::class)->current()?->name; @endphp
@include('payroll._tabs', ['active' => 'reports'])
<div class="bar">
    <div><h1>{{ $r['title'] }}</h1>
        <div class="muted">{{ $company }} · {{ $view === 'advances' ? 'today' : date('F Y', strtotime($from.'-01')).' to '.date('F Y', strtotime($to.'-01')) }}</div></div>
    <div class="actions noprint">
        <button type="button" class="ghost" onclick="window.print()">Print</button>
        <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['download' => 'pdf']) }}">PDF</a>
        <a class="btn ghost" href="{{ request()->fullUrlWithQuery(['download' => 'csv']) }}">Excel / CSV</a>
    </div>
</div>
<form method="GET" action="{{ route('payroll.reports') }}" class="card noprint" style="margin-bottom:16px">
    <div class="pform">
        <div><label for="view">Report</label><select id="view" name="view">@foreach ($views as $k => $label)<option value="{{ $k }}" @selected($k === $view)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="from">From month</label><input id="from" type="month" name="from" value="{{ $from }}"></div>
        <div><label for="to">To month</label><input id="to" type="month" name="to" value="{{ $to }}"></div>
        <div><button>Show</button></div>
    </div>
</form>
<div class="card tbl">
    @if (! $r['rows'])
        <p class="muted" style="margin:0">{{ $view === 'advances' ? 'Nobody owes advances or loans.' : 'No approved pay runs in these months.' }}</p>
    @else
        <table>
            <thead><tr>@foreach ($r['head'] as $i => $h)<th @class(['num' => in_array($i, $r['money'], true)])>{{ $h }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($r['rows'] as $row)
                <tr>@foreach ($row['cells'] as $i => $c)
                    <td @class(['num' => in_array($i, $r['money'], true)])>@if ($i === 0 && $row['url'])<a href="{{ route($row['url'][0], $row['url'][1]) }}">{{ $c }}</a>@elseif (in_array($i, $r['money'], true)){{ Money::format($c) }}@else{{ $c }}@endif</td>
                @endforeach</tr>
            @endforeach
            <tr>@foreach ($r['head'] as $i => $h)<td @class(['num' => in_array($i, $r['money'], true)])><strong>@if ($i === 0)Total @elseif (isset($r['total'][$i])){{ Money::format($r['total'][$i]) }}@endif</strong></td>@endforeach</tr>
            </tbody>
        </table>
    @endif
</div>
<p class="muted">Only approved pay runs count. Gross pay is the salary cost; net pay is what employees receive after advances and loans are taken back.</p>
@endsection
