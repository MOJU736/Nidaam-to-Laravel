<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $d['title'] }} {{ $d['number'] ?? '' }}</title>
<style>
    @page { margin: 28px 34px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11.5px; color: #2b2b2b; line-height: 1.45; }
    h1 { font-size: 22px; letter-spacing: .06em; color: #1f3f99; margin: 0; }
    .company { font-size: 15px; font-weight: bold; text-transform: uppercase; color: #1f3f99; letter-spacing: .03em; }
    .muted { color: #5c6470; }
    table { border-collapse: collapse; width: 100%; }
    .head td { vertical-align: top; }
    .meta td { padding: 2px 0; }
    .meta td.l { color: #5c6470; width: 90px; white-space: nowrap; }
    .lines { margin-top: 16px; border: 1px solid #c7cdd6; }
    .lines th { background: #2f5fd0; color: #fff; padding: 7px 8px; font-size: 10.5px; text-transform: uppercase; text-align: left; }
    .lines td { padding: 6px 8px; border-top: 1px solid #dfe3ea; vertical-align: top; }
    .lines tbody tr:nth-child(even) td { background: #f7f9fc; }
    .r, .lines th.r { text-align: right; white-space: nowrap; }
    .totals { margin-top: 10px; width: 46%; margin-left: 54%; }
    .totals td { padding: 3px 8px; }
    .totals tr.b td { font-weight: bold; border-top: 1px solid #8a93a0; font-size: 13px; }
    .big { margin-top: 14px; border: 2px solid #2f5fd0; padding: 10px 14px; }
    .big td { padding: 2px 0; } .big .v { font-size: 17px; font-weight: bold; text-align: right; }
    .note { margin-top: 14px; } .foot { margin-top: 26px; text-align: center; color: #555; font-size: 10.5px; }
    .stamp { display: inline-block; border: 2px solid #b3261e; color: #b3261e; padding: 2px 10px; font-weight: bold; letter-spacing: .1em; margin-top: 6px; }
</style></head>
<body>
<table class="head"><tr>
    <td style="width:55%"><div class="company">{{ $d['company']['name'] }}</div>
        @if (! empty($d['company']['address']))<div class="muted">{!! nl2br(e($d['company']['address'])) !!}</div>@endif
        @if (! empty($d['company']['email']))<div class="muted">{{ $d['company']['email'] }}</div>@endif</td>
    <td style="text-align:right"><h1>{{ $d['title'] }}</h1>@if (! empty($d['stamp']))<div class="stamp">{{ $d['stamp'] }}</div>@endif</td>
</tr></table>

<table class="head" style="margin-top:18px"><tr>
    <td style="width:55%">
        @if (! empty($d['party']))<div class="muted">{{ $d['party_label'] }}</div><div><strong>{{ $d['party']['name'] }}</strong></div>
            @if (! empty($d['party']['address']))<div>{!! nl2br(e($d['party']['address'])) !!}</div>@endif
            @if (! empty($d['party']['phone']))<div>{{ $d['party']['phone'] }}</div>@endif
            @if (! empty($d['party']['email']))<div>{{ $d['party']['email'] }}</div>@endif
        @endif
    </td>
    <td><table class="meta">@foreach ($d['meta'] as [$l, $v])<tr><td class="l">{{ $l }}</td><td><strong>{{ $v }}</strong></td></tr>@endforeach</table></td>
</tr></table>

@if (! empty($d['big']))
    <div class="big"><table>@foreach ($d['big'] as [$l, $v, $isBig])<tr><td>{{ $l }}</td><td class="{{ $isBig ? 'v' : 'r' }}">{{ $v }}</td></tr>@endforeach</table></div>
@endif

@if (! empty($d['columns']))
    <table class="lines">
        <thead><tr>@foreach ($d['columns'] as $c)<th class="{{ ($c['align'] ?? '') === 'r' ? 'r' : '' }}" @if (! empty($c['w'])) style="width:{{ $c['w'] }}" @endif>{{ $c['h'] }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($d['rows'] as $row)
            <tr @if (! empty($row['_bold'])) style="font-weight:bold" @endif>@foreach ($d['columns'] as $c)<td class="{{ ($c['align'] ?? '') === 'r' ? 'r' : '' }}">{!! nl2br(e((string) ($row[$c['k']] ?? ''))) !!}@if ($c['k'] === 'description' && ! empty($row['sub']))<div class="muted" style="font-size:10px">{{ $row['sub'] }}</div>@endif</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($d['columns']) }}" class="muted" style="text-align:center">{{ $d['empty'] ?? 'Nothing to show.' }}</td></tr>
        @endforelse
        </tbody>
    </table>
@endif

@if (! empty($d['totals']))
    <table class="totals">@foreach ($d['totals'] as [$l, $v, $bold])<tr class="{{ $bold ? 'b' : '' }}"><td>{{ $l }}</td><td class="r">{{ $v }}</td></tr>@endforeach</table>
@endif

@if (! empty($d['notes']))<div class="note"><strong>Notes</strong><br>{!! nl2br(e($d['notes'])) !!}</div>@endif
@if (! empty($d['bank']))<div class="note"><strong>Payment details</strong><br>@foreach ($d['bank'] as [$l, $v]){{ $l }}: {{ $v }}<br>@endforeach</div>@endif
@if (! empty($d['footer']))<div class="foot">{{ $d['footer'] }}</div>@endif
</body></html>
