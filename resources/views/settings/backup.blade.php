@extends('layouts.main')
@section('title', 'Backup')
@section('content')
@include('settings._tabs')
<div class="bar"><h1 style="margin:0">Backup</h1></div>
<div class="card" style="max-width:760px;margin-bottom:16px">
    <p style="margin-top:0">Download a complete copy of <strong>{{ $company }}</strong>'s books: every account, customer, vendor, invoice, bill, payment, item, entry and setting. Keep it somewhere safe off this computer.</p>
    <p class="muted" style="font-size:13px">The file is a standard MySQL backup (<code>.sql.gz</code>). To restore it, import it into an empty database with phpMyAdmin or <code>mysql dbname &lt; file.sql</code>. It contains everything in the company, payroll and the email password included, so treat it as confidential.</p>
    @if ($last)
        <p><strong>Last backup:</strong> {{ \App\Support\Money::date(substr($last['at'], 0, 10)) }} at {{ substr($last['at'], 11, 5) }}{{ ! empty($last['by']) ? ' by '.$last['by'] : '' }}: {{ (int) $last['tables'] }} tables, {{ number_format((int) $last['rows']) }} rows, {{ number_format(($last['bytes'] ?? 0) / 1024) }} KB.
        @if (strtotime($last['at']) < strtotime('-30 days'))<br><span style="color:var(--danger)">That was more than a month ago: download a fresh one.</span>@endif</p>
    @else
        <p style="color:var(--danger)"><strong>No backup has been downloaded yet.</strong></p>
    @endif
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach
    <form method="POST" action="{{ route('settings.backup.download') }}">@csrf<button>Download backup now</button> <span class="muted" style="font-size:13px;margin-left:8px">Can take a few seconds for a big company.</span></form>
</div>
<div class="card" style="max-width:760px">
    <h2 style="margin-top:0">Automatic nightly backups</h2>
    @if (! $status)
        <p style="color:var(--danger)"><strong>Not running yet.</strong> Schedule <code>php artisan backups:run</code> once a day (Windows Task Scheduler, or cron on the server). It backs up every company and the main database (users and companies).</p>
    @else
        <p><strong>Last run:</strong> {{ date('j M Y, g:i a', strtotime($status['finished'])) }}: {{ count($status['done']) }} backed up{{ ! empty($status['errors']) ? ', '.count($status['errors']).' failed' : '' }}.
        @if (strtotime($status['finished']) < strtotime('-2 days'))<br><span style="color:var(--danger)">The nightly job has not run for more than 2 days: check the scheduled task.</span>@endif</p>
        @if (! empty($status['errors']))<ul style="color:var(--danger);font-size:13px">@foreach ($status['errors'] as $e)<li>{{ $e }}</li>@endforeach</ul>@endif
    @endif
    @if ($files)
        <p style="margin-bottom:6px">Copies of <strong>{{ $company }}</strong> (the newest 14 days are kept in <code>storage/app/backups</code>):</p>
        <div class="tbl"><table>
            <thead><tr><th>Date</th><th class="num">Size</th><th></th></tr></thead>
            <tbody>@foreach ($files as $f)<tr><td>{{ date('D j M Y', strtotime($f['date'])) }}</td><td class="num">{{ number_format($f['bytes'] / 1024) }} KB</td><td style="text-align:right"><a class="btn ghost sm" href="{{ route('settings.backup.auto', [$f['date'], $f['file']]) }}">Download</a></td></tr>@endforeach</tbody>
        </table></div>
    @endif
    @if ($main)<p style="margin:10px 0 0"><a href="{{ route('settings.backup.auto', [$main['date'], $main['file']]) }}">Download the latest main database backup</a> <span class="muted" style="font-size:12px">(users, companies and permissions; needed to restore the whole system)</span></p>@endif
    <p class="muted" style="font-size:12px;margin:12px 0 0">These copies are on the same computer as the app. Copy one somewhere else regularly, so losing the computer does not lose the books.</p>
</div>
@endsection
