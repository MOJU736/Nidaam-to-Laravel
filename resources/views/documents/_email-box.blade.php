{{-- The email form for one document (hidden until the Email button is pressed). Needs $type and $id; $opts = statement dates. --}}
@php
    $opts = $opts ?? [];
    $b = app(\App\Services\Documents\DocumentService::class)->build($type, (int) $id, ['start' => $opts['startdate'] ?? null, 'end' => $opts['enddate'] ?? null]);
    $m = $b['mail'] ?? [];
    $box = 'mail-'.$type.'-'.$id;
@endphp
@if ($m)
<form id="{{ $box }}" class="card" method="POST" action="{{ route('documents.email', ['type' => $type, 'id' => $id] + $opts) }}" style="margin-top:18px;max-width:720px" @if (! $errors->has('email')) hidden @endif>
    @csrf
    <h2 style="margin-top:0">Send by email</h2>
    @if ($errors->has('email'))<p class="error">{{ $errors->first('email') }} @if (auth()->user()->isAdmin())<a href="{{ route('settings.email') }}">Email settings</a>@endif</p>@endif
    <p class="muted" style="margin-top:0">The PDF ({{ $b['file'] }}) is attached. Check who it goes to and what it says, then send.</p>
    <label for="{{ $box }}-to" style="margin-top:0">To</label><input id="{{ $box }}-to" name="email_to" value="{{ old('email_to', $m['to']) }}" placeholder="name@example.com, another@example.com">
    <div class="row2"><div><label for="{{ $box }}-cc">CC</label><input id="{{ $box }}-cc" name="email_cc" value="{{ old('email_cc') }}"></div><div><label for="{{ $box }}-bcc">BCC</label><input id="{{ $box }}-bcc" name="email_bcc" value="{{ old('email_bcc') }}"></div></div>
    <label for="{{ $box }}-s">Subject</label><input id="{{ $box }}-s" name="email_subject" maxlength="200" value="{{ old('email_subject', $m['subject']) }}">
    <label for="{{ $box }}-m">Message</label><textarea id="{{ $box }}-m" name="email_message" rows="6" maxlength="5000">{{ old('email_message', $m['message']) }}</textarea>
    @if (empty($m['has_email']))<label style="font-weight:400"><input type="checkbox" name="save_email" value="1" checked> Save this address on the {{ $m['party_table'] === 'vendors' ? 'vendor' : 'customer' }} for next time</label>@endif
    <div class="actions" style="margin-top:14px"><button onclick="return confirm('Send this email now?')">Send email</button><button type="button" class="ghost" onclick="document.getElementById('{{ $box }}').hidden=true">Cancel</button></div>
</form>
@endif
