{{-- "Send reminder" on a customer's page: check who it goes to and what it says, then send. Needs $party. --}}
@php
    $rs = app(\App\Services\Sales\RemindersService::class);
    $rs->ensureTable();
    $info = $rs->info((int) $party->id);
    $show = $info && \App\Support\Money::positive($info['balance']);
    $msg = $show ? $rs->render($info) : null;
@endphp
@if ($show)
<form id="reminderBox" class="card" method="POST" action="{{ route('reminders.one', $party->id) }}" style="margin-top:18px;max-width:720px" @if (! $errors->has('email')) hidden @endif>
    @csrf
    <h2 style="margin-top:0">Send a payment reminder</h2>
    @if ($errors->has('email'))<p class="error">{{ $errors->first('email') }}</p>@endif
    <p class="muted" style="margin-top:0">{{ $party->name }} owes {{ \App\Support\Money::format($info['balance']) }}@if (\App\Support\Money::positive($info['overdue_amount'])), of which {{ \App\Support\Money::format($info['overdue_amount']) }} is overdue ({{ $info['days_overdue'] }} days)@endif.
        @if ($info['last_reminded_at'])Last reminded {{ \App\Support\Money::date(substr($info['last_reminded_at'], 0, 10)) }}.@endif Their statement is attached as a PDF.</p>
    <label for="rem-to" style="margin-top:0">To</label><input id="rem-to" name="email_to" value="{{ old('email_to', $info['email']) }}" placeholder="name@example.com">
    <div class="row2"><div><label for="rem-cc">CC</label><input id="rem-cc" name="email_cc" value="{{ old('email_cc') }}"></div><div><label for="rem-bcc">BCC</label><input id="rem-bcc" name="email_bcc" value="{{ old('email_bcc') }}"></div></div>
    <label for="rem-s">Subject</label><input id="rem-s" name="email_subject" maxlength="200" value="{{ old('email_subject', $msg['subject']) }}">
    <label for="rem-m">Message</label><textarea id="rem-m" name="email_message" rows="8" maxlength="5000">{{ old('email_message', $msg['message']) }}</textarea>
    @if (empty($info['email']))<label style="font-weight:400"><input type="checkbox" name="save_email" value="1" checked> Save this address on the customer for next time</label>@endif
    <div class="actions" style="margin-top:14px"><button onclick="return confirm('Send this reminder now?')">Send reminder</button><button type="button" class="ghost" onclick="document.getElementById('reminderBox').hidden=true">Cancel</button></div>
</form>
@endif
