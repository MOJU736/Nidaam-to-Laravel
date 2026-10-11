<!doctype html><html><head><meta charset="utf-8"><title>Cycle {{ (int) $cd['no'] }} presentation</title>
<style>
body { margin: 0; font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11px; color: #1c2733; background: #fff; }
.ds-slide { padding: 24px 34px 40px; page-break-after: always; }
.ds-slide:last-child { page-break-after: auto; }
.eyebrow { font-size: 9px; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; color: #085041; margin-bottom: 4px; }
h2 { margin: 0 0 3px; font-size: 24px; color: #534AB7; }
.sub { color: #6b7684; font-size: 11px; margin-bottom: 14px; }
.ds-kpis { display: table; width: 100%; border-spacing: 8px 0; margin: 0 -8px 14px; }
.ds-kpi { display: table-cell; width: 25%; background: #f4f6f9; padding: 9px 11px; vertical-align: top; }
.ds-kpi small, .ds-kpi strong, .ds-kpi span { display: block; }
.ds-kpi small { color: #6b7684; font-size: 9px; } .ds-kpi strong { font-size: 17px; margin: 3px 0; } .ds-kpi span { color: #6b7684; font-size: 8.5px; }
.ds-kpi.good strong { color: #1e8e57; } .ds-kpi.bad strong { color: #d64545; } .ds-kpi.warn strong { color: #b27716; }
.ds-cols { display: table; width: 100%; margin-bottom: 10px; }
.ds-cols > .ds-hb { display: table-cell; width: 50%; padding-right: 18px; vertical-align: top; }
.ds-hb h3 { margin: 0 0 6px; font-size: 10px; color: #6b7684; }
.ds-hb .r { display: table; width: 100%; margin-bottom: 6px; } .ds-hb .r > * { display: table-cell; vertical-align: middle; font-size: 10px; }
.ds-hb .r span { width: 110px; } .ds-hb .r b { width: 90px; text-align: right; }
.ds-hb .tr { height: 10px; background: #dfe3ea; } .ds-hb .tr i { display: block; height: 10px; background: #534AB7; } .ds-hb.alt .tr i { background: #085041; }
.ds-tw { border: 1px solid #e7ebf1; margin-bottom: 10px; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 4px 7px; font-size: 10px; text-align: left; border-bottom: 1px solid #e7ebf1; }
th { font-size: 8px; text-transform: uppercase; color: #6b7684; background: #f4f6f9; }
.r { text-align: right; } tfoot td { font-weight: bold; border-top: 2px solid #e7ebf1; } tr { page-break-inside: avoid; }
ul { margin: 0; padding-left: 16px; } li { margin-bottom: 5px; font-size: 11px; } .ds-empty { color: #6b7684; }
.foot { position: fixed; bottom: 8px; left: 34px; font-size: 8px; color: #888; }
</style></head><body>
@foreach ($slides as $slide)
    <section class="ds-slide">@include('datasharing._slide', ['slide' => $slide])</section>
@endforeach
<div class="foot">{{ $company }} &middot; made in Nidaam &middot; {{ \App\Support\Money::date(date('Y-m-d')) }}</div>
</body></html>
