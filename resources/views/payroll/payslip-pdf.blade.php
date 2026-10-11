<!doctype html>
@php use App\Support\Money; $l = $line; @endphp
<html><head><meta charset="utf-8"><title>Payslip</title>
<style>
    @page { margin: 36px 40px; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #2b2b2b; font-size: 12px; line-height: 1.5; }
    .head { text-align: center; margin-bottom: 18px; }
    .company { font-size: 18px; font-weight: bold; color: #1f3f99; text-transform: uppercase; }
    .title { font-size: 18px; font-weight: bold; color: #2f5fd0; margin-top: 2px; }
    table { width: 100%; border-collapse: collapse; }
    .who td { padding: 4px 6px; vertical-align: top; width: 50%; } .who span { color: #777; font-size: 10px; display: block; }
    .rows { margin-top: 14px; } .rows td { padding: 7px 6px; border-bottom: 1px solid #ddd; } .rows td.r { text-align: right; white-space: nowrap; }
    .rows tr.is-total td { font-weight: bold; border-bottom: 2px solid #2b2b2b; }
    .foot { margin-top: 16px; font-size: 10px; color: #666; }
    .sign { margin-top: 56px; } .sign td { width: 45%; padding-top: 4px; border-top: 1px solid #999; font-size: 10px; color: #666; } .sign td.gap { border: 0; width: 10%; }
</style></head><body>
<div class="head">
    <div class="company">{{ $company->name ?? '' }}</div>
    <div class="title">PAYSLIP &mdash; {{ strtoupper($month) }}</div>
</div>
<table class="who">
    <tr><td><span>Employee</span><strong>{{ $l->name }}</strong></td><td><span>Job title</span>{{ $l->position ?: '-' }}{{ $l->department ? ' · '.$l->department : '' }}</td></tr>
    <tr><td><span>Pay date</span>{{ Money::date($run->date) }}</td><td><span>Paid by</span>{{ $methods[$l->method] ?? '' }}{{ $l->pay_account ? ' · '.$l->pay_account : '' }}</td></tr>
</table>
<table class="rows">
    @foreach ($pay->slipRows($l) as [$label, $amount, $kind])
        <tr class="{{ $kind }}"><td>{{ $label }}</td><td class="r">{{ Money::format($amount) }}</td></tr>
    @endforeach
</table>
<div class="foot">@if (Money::positive($owes))Still owed in advances and loans: {{ Money::format($owes) }}. @endif{{ $l->note }}</div>
<table class="sign"><tr><td>Employee signature</td><td class="gap"></td><td>For {{ $company->name ?? '' }}</td></tr></table>
</body></html>
