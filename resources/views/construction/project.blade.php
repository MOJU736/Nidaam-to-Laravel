@extends('layouts.main')
@section('title', 'Project #'.$p->number)
@section('content')
@include('construction._tabs')
@php
    use App\Support\Money; use App\Services\Construction\ConstructionService as C;
    $pct = fn ($x) => rtrim(rtrim((string) $x, '0'), '.') ?: '0';
    $measured = $approvedEstimate && ($p->claim_method ?? 'percent') === 'measured';
    $margin = Money::positive($p->billed) ? round((float) $p->profit / (float) $p->billed * 100) : null;
@endphp
<div class="bar">
    <div><h1>#{{ $p->number }} {{ $p->name }} <span class="pill">{{ C::STATUSES[$p->status] ?? $p->status }}</span></h1>
        <div class="muted">Client: <a href="{{ route('customers.show', $p->customer_id) }}">{{ $p->customer_name }}</a>@if ($p->site) · Site: {{ $p->site }}@endif @if ($p->start_date) · {{ Money::date($p->start_date) }}@if ($p->end_date) – {{ Money::date($p->end_date) }}@endif @endif</div></div>
    <div class="actions">
        <a class="btn sm ghost" href="{{ route('invoices.create', ['customer_id' => $p->customer_id, 'project' => $p->id]) }}">New invoice</a>
        <a class="btn sm ghost" href="{{ route('bills.create', ['project' => $p->id]) }}">New bill</a>
        <a class="btn sm ghost" href="{{ route('projects.edit', $p->id) }}">Edit</a>
    </div>
</div>
@foreach ($errors->all() as $e)<div class="flash bad">{{ $e }}</div>@endforeach
@include('construction._ptabs', ['p' => $p, 'on' => 'overview'])

<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));margin-bottom:16px">
    <div class="card"><div class="muted">Contract</div><div style="font-size:22px;font-weight:700">{{ Money::format($p->contract) }}</div><div class="muted" style="font-size:12px">{{ Money::isZero($p->variations) ? 'No variations' : 'Original '.Money::format($p->contract_amount).' · variations '.Money::format($p->variations) }}</div></div>
    <div class="card"><div class="muted">Costs to date</div><div style="font-size:22px;font-weight:700">{{ Money::format($p->cost) }}</div><div class="muted" style="font-size:12px">{{ $p->budget_used_pct !== null ? $p->budget_used_pct.'% of the '.Money::format($p->budget).' budget' : 'No budget set' }}</div></div>
    <div class="card"><div class="muted">Billed (before tax)</div><div style="font-size:22px;font-weight:700">{{ Money::format($p->billed) }}</div><div class="muted" style="font-size:12px">{{ $p->billed_pct !== null ? $p->billed_pct.'% of contract · '.Money::format($p->left_to_bill).' left to bill' : '' }}</div></div>
    <div class="card"><div class="muted">Profit so far</div><div style="font-size:22px;font-weight:700;{{ Money::cmp($p->profit, 0) < 0 ? 'color:var(--danger)' : '' }}">{{ Money::format($p->profit) }}</div><div class="muted" style="font-size:12px">Client owes {{ Money::format($p->owed) }}{{ $margin !== null ? ' · margin '.$margin.'%' : '' }}</div></div>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr));margin-bottom:16px">
    <div class="card"><div class="muted"><strong>Schedule</strong></div>
        @if ($progress['planned'] !== null)@php $behind = $progress['actual'] < $progress['planned'] - 5; @endphp
            Planned <strong>{{ $progress['planned'] }}%</strong> · done <strong style="{{ $behind ? 'color:var(--danger)' : '' }}">{{ $progress['actual'] }}%</strong>
            <div class="muted" style="font-size:13px">{{ $behind ? 'Behind the plan by '.round($progress['planned'] - $progress['actual'], 1).' points' : ($progress['actual'] > $progress['planned'] + 5 ? 'Ahead of the plan' : 'On plan') }} · <a href="{{ route('projects.schedule', $p->id) }}">Open schedule</a></div>
        @else<div class="muted" style="font-size:13px">No schedule yet. <a href="{{ route('projects.schedule', $p->id) }}">Set up the stages</a> to see planned against done.</div>@endif</div>
    <div class="card"><div class="muted"><strong>Estimate</strong></div>
        @if ($approvedEstimate)<strong>{{ Money::format($approvedEstimate->subtotal) }}</strong> <span class="muted">before tax · estimate {{ $approvedEstimate->number }}, approved {{ Money::date($approvedEstimate->approved_at) }}</span>
            <div class="muted" style="font-size:13px">Expected margin {{ $pct($approvedEstimate->margin_percent) }}% · <a href="{{ route('projects.estimate', $p->id) }}">Open estimate</a></div>
        @else<div class="muted" style="font-size:13px">No approved estimate. <a href="{{ route('projects.estimate', $p->id) }}">Prepare the bill of quantities</a>: approving it sets the contract and the budgets.</div>@endif</div>
</div>

@if ($sectionBudgets->isNotEmpty())
<div class="card tbl" style="margin-bottom:16px">
    <h2 style="margin-top:0">Budget by section</h2>
    <table>
        <thead><tr><th>Section</th><th class="num">Estimate</th><th class="num">Budget</th><th class="num">Work done <span class="muted">(measured claims)</span></th></tr></thead>
        <tbody>@foreach ($sectionBudgets as $sb)<tr><td>{{ $sb->name }}</td><td class="num">{{ Money::format($sb->estimate_amount) }}</td><td class="num">{{ Money::format($sb->budget) }}</td><td class="num">{{ isset($sectionDone[$sb->name]) ? Money::format($sectionDone[$sb->name]) : '' }}</td></tr>@endforeach</tbody>
    </table>
</div>
@endif

<div class="card tbl" style="margin-bottom:16px">
    <h2 style="margin-top:0">Budget against costs</h2>
    <table>
        <thead><tr><th>Cost type</th><th class="num">Budget</th><th class="num">Spent</th><th class="num">Left</th><th class="num">Used</th></tr></thead>
        <tbody>
        @foreach (C::COST_TYPES as $k => $l)
            @php $b = $budgets[$k] ?? '0.00'; $c = $p->costs_by_type[$k] ?? '0.00'; $left = Money::sub($b, $c); @endphp
            <tr><td>{{ $l }}</td><td class="num">{{ Money::format($b) }}</td><td class="num">{{ Money::format($c) }}</td><td class="num" @if (Money::positive($b) && Money::cmp($left, 0) < 0) style="color:var(--danger)" @endif>{{ Money::positive($b) ? Money::format($left) : '' }}</td>
                <td class="num">{{ Money::positive($b) ? round((float) $c / (float) $b * 100).'%' : '' }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><th>Total</th><th class="num">{{ Money::format($p->budget) }}</th><th class="num">{{ Money::format($p->cost) }}</th><th class="num">{{ Money::positive($p->budget) ? Money::format(Money::sub($p->budget, $p->cost)) : '' }}</th><th class="num">{{ $p->budget_used_pct !== null ? $p->budget_used_pct.'%' : '' }}</th></tr></tfoot>
    </table>
</div>

<div class="card" style="margin-bottom:16px" id="claims">
    <h2 style="margin-top:0">Progress claims</h2>
    <div class="row2" style="margin-bottom:10px">
        <div><div class="muted" style="font-size:12px;text-transform:uppercase">Claimed to date</div><strong>{{ Money::format($ct['claimed']) }}</strong> <span class="muted">· {{ $pct($ct['percent']) }}% complete · {{ $ct['claims'] }} claim{{ $ct['claims'] == 1 ? '' : 's' }}</span></div>
        <div><div class="muted" style="font-size:12px;text-transform:uppercase">Retention held</div><strong>{{ Money::format($ct['retention_due']) }}</strong>
            @if (Money::positive($ct['retention_released']))<span class="muted">· {{ Money::format($ct['retention_released']) }} released</span>@endif <span class="muted">· {{ Money::positive($p->retention_percent) ? $pct($p->retention_percent).'% per claim' : 'none on this project' }}</span>
            @if (Money::cmp($ct['retention_due'], 0) < 0)<div style="color:var(--danger);font-size:13px">More retention was released than is held (a claim was voided after the release). Void the retention invoice, then release again.</div>@endif</div>
    </div>
    <div style="margin-bottom:10px"><div class="muted" style="font-size:12px;text-transform:uppercase">Client advance</div>
        @if (Money::positive($ct['advance_received']))<strong>{{ Money::format($ct['advance_received']) }}</strong> <span class="muted">received · {{ Money::format($ct['advance_recovered']) }} taken back · {{ Money::format($ct['advance_left']) }} left</span>
        @elseif (Money::positive($p->advance_amount))<span style="color:var(--danger)">{{ Money::format($p->advance_amount) }} agreed, not recorded yet</span>
        @else<span class="muted">none</span>@endif</div>
    @if ($claims)
        <div class="tbl"><table>
            <thead><tr><th>Claim</th><th>Date</th><th class="num">% to date</th><th class="num">This claim</th><th class="num">Retention</th><th class="num">Advance back</th><th class="num">Net due</th><th>Invoice</th></tr></thead>
            <tbody>
            @foreach ($claims as $c)
                <tr style="{{ $c->live ? '' : 'text-decoration:line-through;opacity:.6' }}">
                    <td>{{ $c->kind === 'retention' ? 'Retention release' : 'Claim '.$c->number }}</td><td>{{ Money::date($c->date) }}</td><td class="num">{{ $c->kind === 'claim' ? $pct($c->percent_to_date).'%' : '' }}</td>
                    <td class="num">{{ Money::format($c->gross) }}</td><td class="num">{{ $c->kind === 'claim' ? Money::format($c->retention) : '' }}</td><td class="num">{{ $c->kind === 'claim' ? Money::format($c->advance) : '' }}</td>
                    <td class="num"><strong>{{ Money::format($c->net) }}</strong></td>
                    <td>@if ($c->invoice_id)<a href="{{ route('invoices.show', $c->invoice_id) }}">INV-{{ str_pad((string) $c->invoice_number, 4, '0', STR_PAD_LEFT) }}</a>{{ $c->live ? '' : ' (void)' }}@endif @if (isset($measuredClaims[$c->id])) · <a href="{{ route('projects.claim-sheet', $c->id) }}" target="_blank">Sheet</a>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
    <div class="row2" style="margin-top:12px">
        <form method="POST" action="{{ route('projects.claims.store', $p->id) }}" onsubmit="return confirm('Create this progress claim? It is issued as an invoice at once.')" @if ($measured) style="grid-column:1/-1" @endif>
            @csrf
            <div class="actions" style="justify-content:space-between;margin-bottom:6px"><h3 style="margin:0">New progress claim</h3>
                @if ($approvedEstimate)<span class="muted" style="font-size:13px">Claim by: <button type="submit" formaction="{{ route('projects.claim-method', $p->id) }}" name="method" value="{{ $measured ? 'percent' : 'measured' }}" formnovalidate class="ghost sm" onclick="return true">{{ $measured ? 'switch to % complete' : 'switch to measured quantities' }}</button></span>@endif</div>
            @if ($measured)
                <p class="muted" style="font-size:13px;margin:0 0 6px">Enter the quantity done <strong>to date</strong> for each line; leave a line alone to keep what was measured last time. Priced at the approved estimate's rates.</p>
                <div class="tbl"><table>
                    <thead><tr><th>Description</th><th>Unit</th><th class="num">BOQ qty</th><th class="num">Done before</th><th class="num" style="width:120px">Done to date</th></tr></thead>
                    <tbody>
                    @foreach ($approvedEstimate->sections as $sec)@continue ($sec->items->isEmpty())
                        <tr style="background:var(--bg)"><td colspan="5"><strong>{{ $sec->name }}</strong></td></tr>
                        @foreach ($sec->items as $it)<tr><td>{{ $it->description }}</td><td>{{ $it->unit }}</td><td class="num">{{ $pct($it->qty) }}</td><td class="num">{{ $pct($measuredPrev[$it->id]['qty'] ?? 0) }}</td>
                            <td><input name="mq[{{ $it->id }}]" inputmode="decimal" class="num" placeholder="{{ $pct($measuredPrev[$it->id]['qty'] ?? 0) }}" style="width:110px"></td></tr>@endforeach
                    @endforeach
                    </tbody>
                </table></div>
                <div class="row2" style="max-width:520px"><div><label>Date</label><input type="date" name="date" required value="{{ now()->toDateString() }}"></div><div><label>Notes <span class="muted">(optional)</span></label><input name="notes" maxlength="400"></div></div>
            @else
                <div class="row2"><div><label style="margin-top:0">% complete to date</label><input name="percent" inputmode="decimal" required placeholder="e.g. 30"></div><div><label style="margin-top:0">Date</label><input type="date" name="date" required value="{{ now()->toDateString() }}"></div></div>
                <label>Notes <span class="muted">(optional)</span></label><input name="notes" maxlength="400">
            @endif
            <div class="actions" style="margin-top:10px"><button>Create claim &amp; invoice</button></div>
            <p class="muted" style="font-size:12px;margin:6px 0 0">Bills the work done since the last claim ({{ Money::format($p->contract) }} contract), holds back {{ $pct($p->retention_percent) }}% retention and takes back the advance in proportion.</p>
        </form>
        <div>
            <form method="POST" action="{{ route('projects.retention', $p->id) }}" style="margin-bottom:14px" onsubmit="return confirm('Invoice the retention held so far?')">
                @csrf <h3 style="margin:0 0 6px">Release retention</h3>
                <div class="actions" style="align-items:end"><div><label style="margin-top:0">Date</label><input type="date" name="date" required value="{{ now()->toDateString() }}"></div><button @disabled(! Money::positive($ct['retention_due']))>Invoice {{ Money::format(Money::positive($ct['retention_due']) ? $ct['retention_due'] : 0) }}</button></div>
            </form>
            <form method="POST" action="{{ route('projects.advances.store', $p->id) }}">
                @csrf <h3 style="margin:0 0 6px">Record the client's advance</h3>
                <div class="row2"><div><label style="margin-top:0">Amount</label><input name="amount" inputmode="decimal" required value="{{ Money::positive($p->advance_amount) && ! Money::positive($ct['advance_received']) ? $p->advance_amount : '' }}"></div><div><label style="margin-top:0">Date</label><input type="date" name="date" required value="{{ now()->toDateString() }}"></div></div>
                <div class="row2"><div><label>Deposited to</label><select name="ledger_id" required><option value="">Choose...</option>@foreach ($banks as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div><div><label>Reference</label><input name="reference" maxlength="100"></div></div>
                <div class="actions" style="margin-top:10px"><button class="ghost">Record advance</button></div>
            </form>
        </div>
    </div>
</div>

<div class="card tbl" style="margin-bottom:16px" id="variations">
    <h2 style="margin-top:0">Variations</h2>
    @if ($variations->isNotEmpty())
        <table><thead><tr><th>Date</th><th>Description</th><th class="num">Amount</th><th></th></tr></thead><tbody>
        @foreach ($variations as $v)
            <tr><td>{{ Money::date($v->date) }}</td><td>{{ $v->description }}</td><td class="num" @if (Money::cmp($v->amount, 0) < 0) style="color:var(--danger)" @endif>{{ Money::format($v->amount) }}</td>
                <td style="text-align:right"><form class="inline" method="POST" action="{{ route('projects.variations.destroy', $v->id) }}" onsubmit="return confirm('Remove this variation?')">@csrf @method('DELETE')<button class="danger sm">Remove</button></form></td></tr>
        @endforeach
        </tbody></table>
    @else<p class="muted">No variations. Record extra work (positive) or work removed (negative) here; the contract changes with them.</p>@endif
    <form method="POST" action="{{ route('projects.variations.store', $p->id) }}" style="margin-top:10px">
        @csrf <div class="actions" style="align-items:end;flex-wrap:wrap">
            <div><label style="margin-top:0">Date</label><input type="date" name="date" required value="{{ now()->toDateString() }}"></div>
            <div style="flex:1;min-width:220px"><label style="margin-top:0">What changed</label><input name="description" maxlength="500" required></div>
            <div><label style="margin-top:0">Amount (+ or -)</label><input name="amount" inputmode="decimal" required style="width:130px"></div><button class="ghost">Add</button></div>
    </form>
</div>

<div class="card tbl" style="margin-bottom:16px">
    <h2 style="margin-top:0">Latest costs</h2>
    @if ($recent)
        <table><thead><tr><th>Date</th><th>Cost type</th><th>Account</th><th>From</th><th class="num">Amount</th></tr></thead><tbody>
        @foreach ($recent as $r)
            <tr><td>{{ Money::date($r->date) }}</td><td>{{ C::COST_TYPES[$r->cost_type] ?? $r->cost_type }}</td><td>{{ $r->ledger_name }}</td><td>@if ($r->url)<a href="{{ $r->url }}">{{ $r->source }}</a>@else{{ $r->source }}@endif</td><td class="num">{{ Money::format($r->signed) }}</td></tr>
        @endforeach
        </tbody></table>
    @else<p class="muted">No costs yet. Tag a bill line, an expense, Money Out or a journal line with this project, or post a wage sheet.</p>@endif
</div>

<div class="card tbl" style="margin-bottom:16px">
    <h2 style="margin-top:0">Invoices</h2>
    @if ($invoices)
        <table><thead><tr><th>Invoice</th><th>Date</th><th>Status</th><th class="num">Total</th><th class="num">Due</th></tr></thead><tbody>
        @foreach ($invoices as $i)
            <tr><td><a href="{{ route('invoices.show', $i->id) }}">INV-{{ str_pad((string) $i->number, 4, '0', STR_PAD_LEFT) }}</a></td><td>{{ Money::date($i->date) }}</td><td>{{ $i->pay_status ?? $i->status }}</td><td class="num">{{ Money::format($i->total) }}</td><td class="num">{{ $i->balance_due !== null ? Money::format($i->balance_due) : '' }}</td></tr>
        @endforeach
        </tbody></table>
    @else<p class="muted">No invoices for this project yet.</p>@endif
</div>

@if (! $invoices && $recent === [] && ! $claims)
<form method="POST" action="{{ route('projects.destroy', $p->id) }}" onsubmit="return confirm('Delete this project?')">@csrf @method('DELETE')<button class="danger sm">Delete project</button></form>
@endif
@endsection
