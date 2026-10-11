<!doctype html>
@php use App\Support\Money; $q = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp
<html><head><meta charset="utf-8"><title>Measurement sheet</title></head><body>
@include('construction.pdf._head', ['docTitle' => 'MEASUREMENT SHEET '.$p->number.'-'.$c->number])
<table class="box"><tr>
    <td><span class="muted">Project</span><br><strong>#{{ $p->number }} {{ $p->name }}</strong></td>
    <td><span class="muted">Client</span><br><strong>{{ $customer->name ?? $p->customer_name }}</strong></td>
    <td class="r"><span class="muted">Progress claim {{ $c->number }}</span><br>{{ Money::date($c->date) }}</td>
</tr></table>
<table class="t">
    <thead><tr><th>Description</th><th>Unit</th><th class="r">BOQ qty</th><th class="r">Previous</th><th class="r">This claim</th><th class="r">To date</th><th class="r">Rate</th><th class="r">Amount to date</th></tr></thead>
    <tbody>
    @php $section = null; @endphp
    @foreach ($lines as $l)
        @if ($l->section_name !== $section) @php $section = $l->section_name; @endphp<tr class="sec"><td colspan="8">{{ $section }}</td></tr>@endif
        @php $before = $prev[$l->estimate_item_id]->qty_to_date ?? 0; @endphp
        <tr><td>{{ $l->description }}</td><td>{{ $l->unit }}</td><td class="r">{{ $q($l->qty) }}</td><td class="r">{{ $q($before) }}</td><td class="r">{{ $q(bcsub((string) $l->qty_to_date, (string) $before, 3)) }}</td><td class="r">{{ $q($l->qty_to_date) }}</td>
            <td class="r">{{ Money::format($l->rate) }}</td><td class="r">{{ Money::format($l->amount_to_date) }}</td></tr>
    @endforeach
    </tbody>
    <tfoot>
        <tr class="tot"><td colspan="7" class="r">Work measured to date</td><td class="r">{{ Money::format($c->gross_to_date) }}</td></tr>
        <tr><td colspan="7" class="r">Less claimed before</td><td class="r">{{ Money::format(Money::sub($c->gross_to_date, $c->gross)) }}</td></tr>
        <tr class="tot"><td colspan="7" class="r">This claim</td><td class="r">{{ Money::format($c->gross) }}</td></tr>
        @if (Money::positive($c->retention))<tr><td colspan="7" class="r">Less retention</td><td class="r">{{ Money::format($c->retention) }}</td></tr>@endif
        @if (Money::positive($c->advance))<tr><td colspan="7" class="r">Less advance taken back</td><td class="r">{{ Money::format($c->advance) }}</td></tr>@endif
        <tr class="tot"><td colspan="7" class="r">Net due</td><td class="r">{{ Money::format($c->net) }}</td></tr>
    </tfoot>
</table>
<table class="sign"><tr><td>Client / engineer approval</td><td class="gap"></td><td>For {{ $company['name'] }}</td></tr></table>
</body></html>
