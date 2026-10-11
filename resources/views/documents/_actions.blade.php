{{-- PDF / Print / Email for one document. Needs $type (a DocumentService type) and $id; $opts = statement dates (startdate, enddate). --}}
@php
    $opts = $opts ?? [];
    $emailable = in_array($type, \App\Services\Documents\DocumentService::EMAILABLE, true);
    $b = $emailable ? app(\App\Services\Documents\DocumentService::class)->build($type, (int) $id, ['start' => $opts['startdate'] ?? null, 'end' => $opts['enddate'] ?? null]) : null;
    $m = $b['mail'] ?? [];
    $box = 'mail-'.$type.'-'.$id;
@endphp
<a class="btn ghost" href="{{ route('documents.pdf', ['type' => $type, 'id' => $id] + $opts) }}" target="_blank" rel="noopener">PDF</a>
@if ($emailable && $m)
    <button type="button" class="ghost" onclick="var e=document.getElementById('{{ $box }}');e.hidden=!e.hidden;if(!e.hidden)e.scrollIntoView({behavior:'smooth',block:'center'})">Email</button>
@endif
