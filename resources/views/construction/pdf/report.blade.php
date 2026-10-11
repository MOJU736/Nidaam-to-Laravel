<!doctype html>
@php use App\Support\Money; $a = $r->auto ?: []; $types = \App\Services\Construction\ConstructionService::COST_TYPES; @endphp
<html><head><meta charset="utf-8"><title>Site report</title></head><body>
@include('construction.pdf._head', ['docTitle' => 'WEEKLY SITE REPORT '.$r->number])
<table class="box"><tr>
    <td><span class="muted">Project</span><br><strong>#{{ $p->number }} {{ $p->name }}</strong>@if ($p->site)<br>{{ $p->site }}@endif</td>
    <td><span class="muted">Week</span><br>{{ Money::date($r->week_start) }} – {{ Money::date($r->week_end) }}</td>
    <td class="r"><span class="muted">Prepared by</span><br>{{ $r->prepared_by }}@if ($r->status === 'approved')<br><span class="muted">Approved by</span> {{ $r->approved_by }}@endif</td>
</tr></table>
@if ($r->actual_pct !== null)<table class="t"><tr><th>Planned progress</th><th>Actual progress</th>@if (! empty($a['workers']))<th>Workers on site</th><th>Man-days</th>@endif</tr>
    <tr><td>{{ (float) $r->planned_pct }}%</td><td>{{ (float) $r->actual_pct }}%</td>@if (! empty($a['workers']))<td>{{ (int) $a['workers'] }}</td><td>{{ (float) $a['man_days'] }}</td>@endif</tr></table>@endif
@if (! empty($a['manpower']))<h2>Manpower</h2><table class="t"><tr><th>Trade</th><th class="r">Workers</th><th class="r">Days</th></tr>@foreach ($a['manpower'] as $m)<tr><td>{{ $m['trade'] }}</td><td class="r">{{ $m['workers'] }}</td><td class="r">{{ $m['days'] }}</td></tr>@endforeach</table>@endif
@if (! empty($a['materials']))<h2>Materials used on site</h2><table class="t"><tr><th>Item</th><th class="r">Qty</th><th class="r">Cost</th></tr>@foreach ($a['materials'] as $m)<tr><td>{{ $m['item'] }}</td><td class="r">{{ $m['qty'] }}</td><td class="r">{{ Money::format($m['amount']) }}</td></tr>@endforeach</table>@endif
@if (! empty($a['costs']))<h2>Costs this week</h2><table class="t"><tr><th>Cost type</th><th class="r">Amount</th></tr>@foreach ($a['costs'] as $k => $v)<tr><td>{{ $types[$k] ?? $k }}</td><td class="r">{{ Money::format($v) }}</td></tr>@endforeach</table>@endif
@if (! empty($a['stages']))<h2>Stages running this week</h2><table class="t"><tr><th>Stage</th><th class="r">Done</th><th class="r">Planned end</th></tr>@foreach ($a['stages'] as $s)<tr><td>{{ $s['name'] }}</td><td class="r">{{ $s['percent_done'] }}%</td><td class="r">{{ Money::date($s['end_date']) }}</td></tr>@endforeach</table>@endif
@foreach ($fields as $k => $label)@if (trim((string) $r->{$k}) !== '')<h2>{{ $label }}</h2><div>{!! nl2br(e($r->{$k})) !!}</div>@endif @endforeach
@if ($photos)<h2>Photos</h2><table width="100%"><tr>@foreach ($photos as $i => $ph)<td width="50%" style="padding:4px;vertical-align:top"><img src="{{ $ph['src'] }}" style="width:100%;max-height:230px"><div class="muted">{{ $ph['caption'] }}</div></td>@if ($i % 2 === 1)</tr><tr>@endif @endforeach</tr></table>@endif
</body></html>
