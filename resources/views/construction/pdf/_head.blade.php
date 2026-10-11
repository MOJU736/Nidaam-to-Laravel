<style>
    @page { margin: 34px 38px; }
    body { font-family: 'DejaVu Sans', sans-serif; color: #2b2b2b; font-size: 11px; line-height: 1.45; }
    .head { width: 100%; border-bottom: 2px solid #1f3f99; padding-bottom: 8px; margin-bottom: 12px; }
    .head td { vertical-align: top; } .company { font-size: 16px; font-weight: bold; color: #1f3f99; text-transform: uppercase; }
    .doc { text-align: right; font-size: 17px; font-weight: bold; color: #2f5fd0; }
    h2 { font-size: 12px; margin: 14px 0 4px; color: #1f3f99; text-transform: uppercase; }
    table.t { width: 100%; border-collapse: collapse; } .t th { background: #eef1f9; text-align: left; padding: 5px 6px; font-size: 10px; border-bottom: 1px solid #c8d0e6; }
    .t td { padding: 4px 6px; border-bottom: 1px solid #e3e3e3; vertical-align: top; } .r { text-align: right; white-space: nowrap; }
    .sec td { background: #f6f7fb; font-weight: bold; } .tot td { font-weight: bold; border-top: 2px solid #2b2b2b; }
    .muted { color: #777; } .box { width: 100%; margin-bottom: 8px; } .box td { padding: 2px 6px 2px 0; vertical-align: top; }
    .sign { width: 100%; margin-top: 48px; } .sign td { width: 45%; border-top: 1px solid #999; padding-top: 4px; font-size: 10px; color: #666; } .sign td.gap { border: 0; width: 10%; }
</style>
<table class="head"><tr>
    <td>@if (! empty($company['logo']))<img src="{{ $company['logo'] }}" style="max-height:46px;max-width:150px;margin-bottom:4px"><br>@endif<span class="company">{{ $company['name'] }}</span>
        <div class="muted">{{ $company['address'] }}{{ $company['address'] && $company['email'] ? ' · ' : '' }}{{ $company['email'] }}</div></td>
    <td class="doc">{{ $docTitle }}</td>
</tr></table>
