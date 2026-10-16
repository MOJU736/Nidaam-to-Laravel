@extends('layouts.main')
@section('title', 'Reconciliation report')
@section('content')
@php
    use App\Support\Money;
    $inName = $nature['in'].'s'; $outName = $nature['out'].'s';
    $uIn = $uOut = '0.00';
    foreach ($uncleared as $u) { $u['in'] !== null ? $uIn = Money::add($uIn, $u['in']) : $uOut = Money::add($uOut, $u['out']); }
    $nIn = count(array_filter($cleared, fn ($c) => $c['in'] !== null)); $nOut = count($cleared) - $nIn;
@endphp
<style>@media print { nav.top, .noprint { display:none !important } .wide { padding:0 !important; max-width:none !important } }</style>
<div class="bar"><div><h1>Reconciliation report</h1><div class="muted">{{ app(\App\Tenancy\TenantManager::class)->current()?->name }} · {{ $ledger->name }} · statement ending {{ Money::date($rec->statement_date) }}@if ($rec->status === 'undone') · <strong style="color:#b3261e">undone</strong>@endif</div></div>
    <div class="actions noprint"><button type="button" class="ghost" onclick="window.print()">Print</button><a class="btn ghost" href="{{ route('banking.history', $ledger->id) }}">History</a></div></div>
<div class="card" style="margin-bottom:16px"><table class="qbr" style="max-width:560px;border-collapse:collapse;width:100%">
    <tr><td>Beginning balance</td><td class="num">{{ Money::format($rec->begin_balance) }}</td></tr>
    <tr><td>{{ $inName }} cleared ({{ $nIn }})</td><td class="num">+ {{ Money::format($sum['in']) }}</td></tr>
    <tr><td>{{ $outName }} cleared ({{ $nOut }})</td><td class="num">− {{ Money::format($sum['out']) }}</td></tr>
    <tr style="font-weight:700;border-top:1px solid #999"><td>Statement ending balance</td><td class="num">{{ Money::format($rec->statement_balance) }}</td></tr>
    <tr><td>Uncleared {{ strtolower($inName) }} up to {{ Money::date($rec->statement_date) }}</td><td class="num">+ {{ Money::format($uIn) }}</td></tr>
    <tr><td>Uncleared {{ strtolower($outName) }} up to {{ Money::date($rec->statement_date) }}</td><td class="num">− {{ Money::format($uOut) }}</td></tr>
    <tr style="font-weight:700;border-top:1px solid #999"><td>Balance in the books on {{ Money::date($rec->statement_date) }}</td><td class="num">{{ Money::format($book) }}</td></tr>
</table>
<p class="muted" style="margin-bottom:0">Finished {{ $rec->finished_at ? Money::date(substr($rec->finished_at, 0, 10)) : '' }}@if ($rec->finished_by) by {{ $rec->finished_by }}@endif. Uncleared items are as they are today.</p></div>

@foreach ([['Cleared transactions', $cleared, 'No transactions were ticked in this reconciliation.'], ['Still uncleared (up to '.Money::date($rec->statement_date).')', $uncleared, 'Nothing uncleared.']] as [$heading, $rows, $empty])
    <h2>{{ $heading }}</h2>
    @if (! $rows)<div class="card muted">{{ $empty }}</div>@else
    <div class="card tbl"><table>
        <thead><tr><th>Date</th><th>Type</th><th>No.</th><th>Name / account</th><th>Memo</th><th class="num">{{ $inName }}</th><th class="num">{{ $outName }}</th></tr></thead>
        <tbody>@foreach ($rows as $r)<tr><td>{{ Money::date($r['date']) }}</td><td>{{ $r['type'] }}</td><td><a href="{{ $r['url'] }}">{{ $r['num'] }}</a></td><td>{{ $r['name'] }}</td><td>{{ $r['memo'] }}</td>
            <td class="num">{{ $r['in'] !== null ? Money::format($r['in']) : '' }}</td><td class="num">{{ $r['out'] !== null ? Money::format($r['out']) : '' }}</td></tr>@endforeach</tbody>
    </table></div>@endif
@endforeach
@if ($isLast)
    <form class="noprint" method="POST" action="{{ route('banking.undo', $rec->id) }}" style="margin-top:16px" onsubmit="return confirm('Undo this reconciliation? Its transactions become uncleared again.')">@csrf<button class="danger">Undo this reconciliation</button></form>
@endif
@endsection
