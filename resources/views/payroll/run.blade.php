@extends('layouts.main')
@section('title', 'Payroll '.$month)
@section('content')
@php
    use App\Support\Money;
    $r = $run;
    $draft = $r->status === 'draft';
    $void = $r->status === 'void';
    $paidAll = '0.00';
    foreach ($r->lines as $l) { $paidAll = Money::add($paidAll, $l->paid); }
    $due = Money::sub($r->net, $paidAll);
    $statusName = ['draft' => 'Draft', 'approved' => Money::positive($due) ? (Money::positive($paidAll) ? 'Part paid' : 'Approved, not paid') : 'Paid', 'void' => 'Void'];
    $statusCls = $r->status === 'approved' && ! Money::positive($due) ? 'paid' : ($r->status === 'approved' && Money::positive($paidAll) ? 'part' : $r->status);
    $num = fn ($v) => ((string) $v === '' || Money::isZero($v)) ? '' : rtrim(rtrim((string) $v, '0'), '.');
    $sample = Money::format('1.00');
    $sym = trim(preg_replace('/[\d.,\-]/', '', $sample));
    $dp = str_contains($sample, '.') ? strlen(substr($sample, strpos($sample, '.') + 1)) : 0;
@endphp
@include('payroll._tabs', ['active' => 'index'])
<div class="bar">
    <div>
        <h1 style="margin:0">Payroll - {{ $month }} <span class="pstat is-{{ $statusCls }}">{{ $statusName[$r->status] }}</span></h1>
        <div class="muted">{{ count($r->lines) }} employee{{ count($r->lines) === 1 ? '' : 's' }} · pay date {{ Money::date($r->date) }}{{ $r->created_by ? ' · made by '.$r->created_by : '' }}</div>
    </div>
    @if (! $draft)
        <div class="actions noprint">
            <button type="button" class="ghost" onclick="window.print()">Print</button>
            @if ($r->entry_id)<a class="btn ghost" href="{{ route('entries.show', ['journal', $r->entry_id]) }}">Entry</a>@endif
        </div>
    @endif
</div>

@if ($draft)
<form method="POST" action="{{ route('payroll.runs.update', $r->id) }}" id="runForm" autocomplete="off">@csrf @method('PUT')
    <div class="card" style="margin-bottom:16px">
        <div class="pform"><div><label for="date">Pay date</label><input id="date" name="date" type="date" value="{{ old('date', substr($r->date, 0, 10)) }}" required></div></div>
    </div>
    <div class="card tbl pgrid">
        <table>
            <thead><tr><th>Employee</th><th class="num">Salary</th><th class="num">Allowance</th><th class="num">Overtime</th><th class="num">Bonus</th><th class="num">Absent days</th><th class="num">Gross</th><th class="num">Advance / loan back</th><th class="num">Net pay</th><th>Note</th></tr></thead>
            <tbody>
            @foreach ($r->lines as $l)
                @php $id = $l->id; $in = old("line.$id"); $val = fn ($k) => $in !== null ? ($in[$k] ?? '') : $num($l->$k); $owe = $owes[$id] ?? '0.00'; @endphp
                <tr class="prow" data-salary="{{ $l->salary }}" data-owes="{{ $owe }}">
                    <td><strong>{{ $l->name }}</strong><span class="psub">{{ $l->position }}{{ Money::positive($owe) ? ' · owes '.Money::format($owe) : '' }}</span></td>
                    <td class="num">{{ Money::format($l->salary) }}</td>
                    <td class="num"><input name="line[{{ $id }}][allowance]" data-k="allowance" inputmode="decimal" value="{{ $val('allowance') }}" placeholder="0" aria-label="Allowance for {{ $l->name }}"></td>
                    <td class="num"><input name="line[{{ $id }}][overtime]" data-k="overtime" inputmode="decimal" value="{{ $val('overtime') }}" placeholder="0" aria-label="Overtime for {{ $l->name }}"></td>
                    <td class="num"><input name="line[{{ $id }}][bonus]" data-k="bonus" inputmode="decimal" value="{{ $val('bonus') }}" placeholder="0" aria-label="Bonus for {{ $l->name }}"></td>
                    <td class="num"><input class="pdays" name="line[{{ $id }}][absent_days]" data-k="absent_days" inputmode="decimal" value="{{ $val('absent_days') }}" placeholder="0" aria-label="Absent days for {{ $l->name }}"></td>
                    <td class="num" data-out="gross">{{ Money::format($l->gross) }}</td>
                    <td class="num"><input name="line[{{ $id }}][repay]" data-k="repay" inputmode="decimal" value="{{ $val('repay') }}" placeholder="0" aria-label="Repayment for {{ $l->name }}" @if (! Money::positive($owe)) readonly tabindex="-1" @endif><span class="pbad" data-out="bad" hidden></span></td>
                    <td class="num" data-out="net"><strong>{{ Money::format($l->net) }}</strong></td>
                    <td><input class="pnote" name="line[{{ $id }}][note]" maxlength="255" value="{{ $in !== null ? ($in['note'] ?? '') : $l->note }}" aria-label="Note for {{ $l->name }}"></td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr><td><strong>Total</strong></td><td colspan="5"></td><td class="num"><strong id="totGross">{{ Money::format($r->gross) }}</strong></td><td class="num"><strong id="totRepay">{{ Money::format($r->repay) }}</strong></td><td class="num"><strong id="totNet">{{ Money::format($r->net) }}</strong></td><td></td></tr></tfoot>
        </table>
    </div>
    <p class="muted">Absent pay = salary ÷ 30 × absent days. Gross = salary − absent pay + allowance + overtime + bonus. Net = gross − advance or loan taken back. Approving posts Salaries expense (Dr) against Salaries payable and Staff advances (Cr).</p>
    <div class="actions">
        <button type="submit" name="save" value="1" class="ghost">Save draft</button>
        <button type="submit" name="approve" value="1" id="approveBtn" onclick="return confirm('Approve {{ $month }}? Salaries are posted to the accounts and the draft can no longer be changed (you can still void it).')">Approve</button>
        <span style="flex:1"></span>
        <button type="submit" form="delForm" class="ghost danger">Delete draft</button>
    </div>
</form>
<form id="delForm" method="POST" action="{{ route('payroll.runs.destroy', $r->id) }}" onsubmit="return confirm('Delete the {{ $month }} draft? Nothing was posted yet.')" hidden>@csrf @method('DELETE')</form>
<script>
(function () {
    var sym = @json($sym), dp = {{ (int) $dp }};
    function n(v) { var x = parseFloat(String(v || '').replace(/[, $]/g, '')); return isNaN(x) ? 0 : x; }
    function r2(x) { return Math.round(x * 100) / 100; }
    function money(x) { var neg = x < 0; var s = Math.abs(x).toFixed(dp).replace(/\B(?=(\d{3})+(?!\d))/g, ','); return (neg ? '-' : '') + sym + s; }
    function calc() {
        var tg = 0, tr = 0, tn = 0, bad = false;
        document.querySelectorAll('.prow').forEach(function (row) {
            var sal = n(row.dataset.salary), owes = n(row.dataset.owes), v = {};
            row.querySelectorAll('[data-k]').forEach(function (i) { v[i.dataset.k] = n(i.value); });
            var absent = Math.min(r2(sal / 30 * v.absent_days), sal);
            var g = r2(sal - absent + v.allowance + v.overtime + v.bonus), net = r2(g - v.repay);
            var msg = v.repay > owes + 0.001 ? 'Owes only ' + money(owes) : (net < 0 ? 'More than the pay' : '');
            row.querySelector('[data-out=gross]').textContent = money(g);
            row.querySelector('[data-out=net]').innerHTML = '<strong>' + money(net) + '</strong>';
            var b = row.querySelector('[data-out=bad]'); b.textContent = msg; b.hidden = !msg;
            if (msg) bad = true;
            tg += g; tr += v.repay; tn += net;
        });
        document.getElementById('totGross').textContent = money(r2(tg));
        document.getElementById('totRepay').textContent = money(r2(tr));
        document.getElementById('totNet').textContent = money(r2(tn));
        document.getElementById('approveBtn').disabled = bad || tg <= 0;
    }
    document.getElementById('runForm').addEventListener('input', calc);
    calc();
})();
</script>
@else
    <div class="grid" style="margin-bottom:16px">
        <div class="stat"><b>{{ Money::format($r->gross) }}</b><span class="muted">gross pay</span></div>
        <div class="stat"><b>{{ Money::format($r->repay) }}</b><span class="muted">advances &amp; loans taken back</span></div>
        <div class="stat"><b>{{ Money::format($r->net) }}</b><span class="muted">net pay</span></div>
        <div class="stat"><b @style(['color:var(--danger)' => ! $void && Money::positive($due)])>{{ Money::format($void ? 0 : $due) }}</b><span class="muted">still to pay</span></div>
    </div>
    @if (! $void && Money::positive($due))
        <form method="POST" action="{{ route('payroll.runs.pay', $r->id) }}" id="payForm" class="card noprint" style="margin-bottom:16px">@csrf
            <div class="pform">
                <div><label for="paydate">Payment date</label><input id="paydate" name="date" type="date" value="{{ old('date', max(substr($r->date, 0, 10), min(date('Y-m-d'), date('Y-m-t', strtotime($r->date))))) }}" required></div>
                <div><label for="payfrom">Pay from</label>
                    <select id="payfrom" name="ledger_id" required>@foreach ($banks as $id => $name)<option value="{{ $id }}" @selected((string) old('ledger_id', $r->lines ? ($suggest[$r->lines[0]->id] ?? '') : '') === (string) $id)>{{ $name }}</option>@endforeach</select></div>
                <div><button type="submit" name="line_id" value="0" onclick="return confirm('Pay everyone still unpaid ({{ Money::format($due) }})?')">Pay everyone still unpaid ({{ Money::format($due) }})</button></div>
            </div>
            @if (! $banks)<p class="field-error">There is no cash or bank account yet. Make one under Accounts first.</p>@endif
        </form>
    @endif
    <div class="card tbl">
        <table>
            <thead><tr><th>Employee</th><th class="num">Gross</th><th class="num">Taken back</th><th class="num">Net</th><th class="num">Still to pay</th><th class="noprint"></th></tr></thead>
            <tbody>
            @foreach ($r->lines as $l)
                @php $left = Money::sub($l->net, $l->paid); @endphp
                <tr>
                    <td><a href="{{ route('payroll.employees.show', $l->employee_id) }}"><strong>{{ $l->name }}</strong></a><span class="psub">{{ $methods[$l->method] ?? '' }}{{ $l->pay_account ? ' · '.$l->pay_account : '' }}{{ $l->note ? ' · '.$l->note : '' }}</span></td>
                    <td class="num">{{ Money::format($l->gross) }}</td>
                    <td class="num">{{ Money::positive($l->repay) ? Money::format($l->repay) : '' }}</td>
                    <td class="num">{{ Money::format($l->net) }}</td>
                    <td class="num">@if (! $void)@if (Money::positive($left)){{ Money::format($left) }}@else<span class="pstat is-paid">Paid</span>@endif @endif</td>
                    <td class="num noprint">
                        @if (! $void && Money::positive($left) && Money::positive($due))
                            <button type="submit" form="payForm" name="line_id" value="{{ $l->id }}" class="ghost sm" data-acc="{{ $suggest[$l->id] ?? '' }}" onclick="var s = document.getElementById('payfrom'); if (this.dataset.acc && ! s.dataset.touched) s.value = this.dataset.acc;">Pay</button>
                        @endif
                        @if (! $void)<a class="btn ghost sm" href="{{ route('payroll.payslip', $l->id) }}">Payslip</a>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <script>document.getElementById('payfrom')?.addEventListener('change', function () { this.dataset.touched = 1; });</script>

    @if ($r->payments)
        <h2>Payments</h2>
        @php $names = collect($r->lines)->pluck('name', 'id'); @endphp
        <div class="card tbl"><table>
            <thead><tr><th>Date</th><th>For</th><th>From</th><th class="num">Amount</th><th class="noprint"></th></tr></thead>
            <tbody>@foreach ($r->payments as $p)
                <tr><td>{{ Money::date($p->date) }}</td><td>{{ $p->line_id ? ($names[$p->line_id] ?? '') : 'Everyone unpaid' }}</td><td>{{ $p->ledger_name }}</td><td class="num">{{ Money::format($p->amount) }}</td>
                    <td class="num noprint"><form method="POST" action="{{ route('payroll.payments.void', $p->id) }}" class="inline" onsubmit="var x = prompt('Undo this payment of {{ Money::format($p->amount) }}? Its entry is removed and the pay is due again. Reason (optional):'); if (x === null) return false; this.void_reason.value = x;">@csrf<input type="hidden" name="void_reason"><button class="ghost sm danger">Undo</button></form></td></tr>
            @endforeach</tbody>
        </table></div>
    @endif

    @if (! $void)
        <div class="noprint" style="margin-top:16px">
            @if ($r->payments)
                <p class="muted">To void this pay run, undo its payments first.</p>
            @else
                <form method="POST" action="{{ route('payroll.runs.void', $r->id) }}" class="inline" onsubmit="var x = prompt('Void the {{ $month }} pay run? Its entry is removed and the advances taken back are owed again. Reason (optional):'); if (x === null) return false; this.void_reason.value = x;">@csrf<input type="hidden" name="void_reason"><button class="ghost sm danger">Void this pay run</button></form>
            @endif
        </div>
    @endif
@endif
@endsection
