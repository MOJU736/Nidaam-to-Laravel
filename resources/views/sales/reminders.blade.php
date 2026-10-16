@extends('layouts.main')
@section('title', 'Payment reminders')
@section('content')
@php use App\Support\Money; $cutoff = strtotime('-'.$recentDays.' days'); @endphp
@include('sales._tabs')
<a class="back" href="{{ route('customers.index') }}">&larr; Customers</a>
<div class="bar"><h1>Payment reminders</h1></div>

@if ($results)
    <div class="card" style="margin-bottom:14px">
        <strong>{{ (int) $results['sent'] }}</strong> reminder{{ (int) $results['sent'] === 1 ? '' : 's' }} sent.
        @if ($results['failed'])<div class="flash bad" style="margin:8px 0 0"><strong>Could not send:</strong><ul style="margin:4px 0 0;padding-left:18px">@foreach ($results['failed'] as $l)<li>{{ $l }}</li>@endforeach</ul></div>@endif
        @if ($results['skipped'])<div class="flash bad" style="margin:8px 0 0"><strong>Skipped:</strong><ul style="margin:4px 0 0;padding-left:18px">@foreach ($results['skipped'] as $l)<li>{{ $l }}</li>@endforeach</ul></div>@endif
    </div>
@endif

<p class="muted" style="margin-top:0">Every customer who owes money. Overdue customers are listed first and start ticked; customers who owe but are not past due start unticked, so you can choose to remind them too. What is overdue comes from the payments applied to each invoice. Each reminder emails the customer their statement as a PDF. Nothing is sent until you tick customers and press <strong>Send reminders</strong>. Customers reminded in the last {{ $recentDays }} days start unticked.</p>

@if (! $rows)
    <div class="card muted" style="text-align:center;padding:30px">Nobody owes anything right now.</div>
@else
<form method="POST" action="{{ route('reminders.send') }}" id="remForm">
    @csrf
    <div class="card tbl"><table>
        <thead><tr><th style="width:34px"><input type="checkbox" id="remAll" title="Tick or untick all" aria-label="All"></th><th>Customer</th><th>Email</th><th class="num">Owes</th><th class="num">Overdue</th><th class="num">Days late</th><th>Last reminded</th></tr></thead>
        <tbody>
        @foreach ($rows as $r)
            @php $recent = $r['last_reminded_at'] && strtotime($r['last_reminded_at']) >= $cutoff; $tick = $r['is_overdue'] && ! $recent && $r['email']; @endphp
            <tr>
                <td><input type="checkbox" class="rem" name="customer_ids[]" value="{{ $r['customer_id'] }}" @checked($tick) @disabled(! $r['email']) aria-label="Remind {{ $r['customer_name'] }}"></td>
                <td><a href="{{ route('customers.show', $r['customer_id']) }}">{{ $r['customer_name'] }}</a></td>
                <td>@if ($r['email']){{ $r['email'] }}@else<span class="muted">no email on file</span>@endif</td>
                <td class="num">{{ Money::format($r['balance']) }}</td>
                <td class="num">@if ($r['is_overdue'])<span style="color:#b3261e">{{ Money::format($r['overdue_amount']) }}</span>@else<span class="muted">—</span>@endif</td>
                <td class="num">{{ $r['is_overdue'] ? $r['days_overdue'] : '' }}</td>
                <td>@if ($r['last_reminded_at']){{ Money::date(substr($r['last_reminded_at'], 0, 10)) }}@if ($recent) <span class="pill owes">recent</span>@endif @else<span class="muted">never</span>@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="card" style="margin-top:12px">
        <div class="row2"><div><label for="email_cc" style="margin-top:0">Copy every reminder to (CC)</label><input id="email_cc" name="email_cc" placeholder="optional" value="{{ old('email_cc', app(\App\Services\Documents\EmailService::class)->defaultCc()) }}"></div><div><label for="email_bcc" style="margin-top:0">Hidden copy (BCC)</label><input id="email_bcc" name="email_bcc" placeholder="optional" value="{{ old('email_bcc') }}"></div></div>
        <div class="actions" style="margin-top:14px"><button id="remSend" onclick="return confirm('Send ' + document.querySelectorAll('.rem:checked').length + ' payment reminder(s) now?')">Send reminders (<span id="remCount">0</span>)</button><span class="muted">Up to {{ $limit }} at a time.</span></div>
    </div>
</form>
@endif

@if ($preview)
    <h2>What the email says</h2>
    <div class="card"><p class="muted" style="margin-top:0">Shown for {{ $previewFor }}. Their statement is attached as a PDF.</p><strong>{{ $preview['subject'] }}</strong><div style="margin-top:8px;white-space:pre-line">{{ $preview['message'] }}</div></div>
@endif

@if ($isAdmin)
    <h2>Change the wording</h2>
    <form class="card" method="POST" action="{{ route('reminders.message') }}" style="max-width:760px">
        @csrf
        <p class="muted" style="margin-top:0">You can use <code>{customer}</code> <code>{company}</code> <code>{balance}</code> <code>{overdue_amount}</code> <code>{days_overdue}</code> <code>{oldest_due_date}</code> <code>{invoices}</code> <code>{overdue_line}</code> <code>{invoices_line}</code>. Leave a box empty to use the standard text.</p>
        <label for="reminder_subject">Subject</label><input id="reminder_subject" name="reminder_subject" maxlength="255" placeholder="{{ \App\Services\Sales\RemindersService::DEFAULT_SUBJECT }}" value="{{ old('reminder_subject', \App\Models\Tenant\Setting::first()->reminder_subject) }}">
        <label for="reminder_body">Message</label><textarea id="reminder_body" name="reminder_body" rows="9" maxlength="3000" placeholder="{{ \App\Services\Sales\RemindersService::DEFAULT_BODY }}">{{ old('reminder_body', \App\Models\Tenant\Setting::first()->reminder_body) }}</textarea>
        <div class="actions" style="margin-top:14px"><button>Save wording</button></div>
    </form>
@endif
<script>
(function () {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.rem')), all = document.getElementById('remAll');
    function count() { var n = boxes.filter(function (b) { return b.checked; }).length; var c = document.getElementById('remCount'); if (c) c.textContent = n; var s = document.getElementById('remSend'); if (s) s.disabled = n === 0; }
    boxes.forEach(function (b) { b.addEventListener('change', count); });
    if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { if (!b.disabled) b.checked = all.checked; }); count(); });
    count();
})();
</script>
@endsection
