@extends('layouts.main')
@section('title', 'Site report '.$r->number)
@section('content')
@include('construction._tabs')
@include('construction._ptabs', ['p' => $p, 'on' => 'reports'])
@php use App\Support\Money; $a = $r->auto ?: []; $locked = $r->status === 'approved'; @endphp
@foreach ($errors->all() as $er)<div class="flash bad">{{ $er }}</div>@endforeach
<div class="bar"><div><h1 style="margin:0">Site report {{ $r->number }} <span class="pill">{{ $statuses[$r->status] ?? $r->status }}</span></h1>
    <div class="muted">{{ Money::date($r->week_start) }} – {{ Money::date($r->week_end) }} · prepared by {{ $r->prepared_by }}@if ($r->approved_at) · approved by {{ $r->approved_by }} {{ Money::date($r->approved_at) }}@endif</div></div>
    <div class="actions"><a class="btn sm ghost" href="{{ route('projects.report.pdf', $r->id) }}" target="_blank">PDF</a>@if ($whatsapp)<a class="btn sm ghost" href="{{ $whatsapp }}" target="_blank" rel="noopener">WhatsApp</a>@endif<a class="btn sm ghost" href="{{ route('projects.reports', $p->id) }}">All reports</a></div></div>
@if ($r->return_note)<div class="flash bad"><strong>Returned for changes:</strong> {{ $r->return_note }}</div>@endif

<div class="card" style="margin-bottom:14px">
    <h2 style="margin-top:0">This week, from the books</h2>
    <div class="row2">
        <div><span class="muted">Progress</span><div style="font-size:20px;font-weight:700">{{ $r->actual_pct !== null ? (float) $r->actual_pct.'% done' : 'No schedule' }}</div><div class="muted">{{ $r->planned_pct !== null ? 'planned '.(float) $r->planned_pct.'%' : '' }}</div></div>
        <div><span class="muted">On site</span><div style="font-size:20px;font-weight:700">{{ (int) ($a['workers'] ?? 0) }} workers</div><div class="muted">{{ (float) ($a['man_days'] ?? 0) }} man-days</div></div>
    </div>
    @if (! empty($a['manpower']))<h3>Manpower</h3><div class="tbl"><table><tr><th>Trade</th><th class="num">Workers</th><th class="num">Days</th></tr>@foreach ($a['manpower'] as $m)<tr><td>{{ $m['trade'] }}</td><td class="num">{{ $m['workers'] }}</td><td class="num">{{ $m['days'] }}</td></tr>@endforeach</table></div>@endif
    @if (! empty($a['materials']))<h3>Materials used on site</h3><div class="tbl"><table><tr><th>Item</th><th class="num">Qty</th><th class="num">Cost</th></tr>@foreach ($a['materials'] as $m)<tr><td>{{ $m['item'] }}</td><td class="num">{{ $m['qty'] }}</td><td class="num">{{ Money::format($m['amount']) }}</td></tr>@endforeach</table></div>@endif
    @if (! empty($a['costs']))<h3>Costs posted</h3><div class="tbl"><table><tr><th>Cost type</th><th class="num">Amount</th></tr>@foreach ($a['costs'] as $k => $v)<tr><td>{{ $costTypes[$k] ?? $k }}</td><td class="num">{{ Money::format($v) }}</td></tr>@endforeach</table></div>@endif
    @if (! empty($a['stages']))<h3>Stages running</h3><div class="tbl"><table><tr><th>Stage</th><th class="num">Done</th><th class="num">Planned end</th></tr>@foreach ($a['stages'] as $s)<tr><td>{{ $s['name'] }}</td><td class="num">{{ $s['percent_done'] }}%</td><td class="num">{{ Money::date($s['end_date']) }}</td></tr>@endforeach</table></div>@endif
</div>

<form class="card" method="POST" action="{{ route('projects.report.save', $r->id) }}" style="margin-bottom:14px">
    @csrf @method('PUT')
    <h2 style="margin-top:0">The engineer's report</h2>
    @foreach ($fields as $k => $label)
        <label for="f_{{ $k }}" style="{{ $loop->first ? 'margin-top:0' : '' }}">{{ $label }}</label>
        @if ($k === 'weather')<input id="f_{{ $k }}" name="{{ $k }}" maxlength="255" value="{{ old($k, $r->{$k}) }}" @disabled($locked)>
        @else<textarea id="f_{{ $k }}" name="{{ $k }}" rows="{{ $k === 'work_done' ? 6 : 3 }}" maxlength="5000" @disabled($locked)>{{ old($k, $r->{$k}) }}</textarea>@endif
    @endforeach
    @unless ($locked)
        <label style="font-weight:400"><input type="checkbox" name="refresh" value="1"> Bring the figures above up to date (progress, manpower, materials, costs)</label>
        <div class="actions" style="margin-top:12px"><button>Save</button>@if ($r->status === 'draft')<button name="then" value="submit" class="ghost" onclick="return confirm('Send this report for approval?')">Save &amp; send for approval</button>@endif</div>
    @endunless
</form>

@if ($r->status === 'submitted' && $canApprove)
<form class="card" method="POST" action="{{ route('projects.report.status', $r->id) }}" style="margin-bottom:14px">@csrf
    <h2 style="margin-top:0">Approval</h2>
    <div class="actions" style="align-items:end"><button name="action" value="approve">Approve</button><div style="flex:1;min-width:240px"><label style="margin-top:0">Or return it, saying what to change</label><input name="note" maxlength="500"></div><button name="action" value="return" class="ghost">Return</button></div>
</form>
@endif
@if ($locked && $canApprove)
<form method="POST" action="{{ route('projects.report.status', $r->id) }}" style="margin-bottom:14px" onsubmit="return confirm('Reopen this approved report for changes?')">@csrf<input type="hidden" name="action" value="reopen"><button class="ghost sm">Reopen for changes</button></form>
@endif

<div class="card" id="photos" style="margin-bottom:14px">
    <h2 style="margin-top:0">Photos ({{ $r->photos->count() }}/8)</h2>
    <div class="actions" style="align-items:flex-start;flex-wrap:wrap">
        @foreach ($r->photos as $ph)
            <div style="width:180px"><a href="{{ asset('uploads/site/'.$ph->file) }}" target="_blank"><img src="{{ asset('uploads/site/'.$ph->file) }}" alt="" style="width:180px;height:130px;object-fit:cover;border-radius:6px"></a>
                <div class="muted" style="font-size:12px">{{ $ph->caption }}</div>
                @unless ($locked)<form method="POST" action="{{ route('projects.report.photos.destroy', $ph->id) }}" onsubmit="return confirm('Remove this photo?')">@csrf @method('DELETE')<button class="danger sm">Remove</button></form>@endunless</div>
        @endforeach
    </div>
    @unless ($locked)
    <form method="POST" action="{{ route('projects.report.photos', $r->id) }}" enctype="multipart/form-data" style="margin-top:10px">@csrf
        <div class="actions" style="align-items:end"><div><label style="margin-top:0">Photos (JPG, PNG, WebP)</label><input type="file" name="photos[]" accept="image/*" multiple></div><div style="flex:1"><label style="margin-top:0">Caption</label><input name="caption" maxlength="255"></div><button class="ghost">Add</button></div>
    </form>
    @endunless
</div>

<div class="actions">
    <form class="card" method="POST" action="{{ route('projects.report.email', $r->id) }}" style="flex:1;min-width:300px">@csrf <h3 style="margin:0 0 6px">Email the report</h3>
        <div class="actions" style="align-items:end"><div style="flex:1"><input name="email" value="{{ $customerEmail }}" placeholder="client@example.com"></div><button class="ghost" onclick="return confirm('Send the report PDF by email now?')">Send</button></div></form>
    @unless ($locked)<form method="POST" action="{{ route('projects.report.destroy', $r->id) }}" onsubmit="return confirm('Delete this report?')">@csrf @method('DELETE')<button class="danger sm">Delete report</button></form>@endunless
</div>
@endsection
