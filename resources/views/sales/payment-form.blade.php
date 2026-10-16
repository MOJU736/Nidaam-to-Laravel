@extends('layouts.main')
@php
    use App\Support\Money;
    $editing = (bool) $payment;
    $hadWht = $editing && Money::positive($payment->wht_amount ?? 0);
    $v = fn ($f, $default = '') => old($f, $payment->{$f} ?? $default);
@endphp
@section('title', $editing ? 'Edit payment' : 'Receive payment')
@section('content')
@include('sales._tabs')
<a class="back" href="{{ $editing ? route('payments.show', $payment->id) : route('payments.index') }}">&larr; {{ $editing ? 'Back to the payment' : 'Payments' }}</a>
<div class="bar"><h1>{{ $editing ? 'Edit payment '.\App\Services\Sales\Allocations::docLabel('', $payment->number) : 'Receive payment' }}</h1></div>

<form class="card" method="POST" action="{{ $editing ? route('payments.update', $payment->id) : route('payments.store') }}" style="max-width:860px">
    @csrf
    @if ($editing) @method('PUT') @endif
    @foreach (['number', 'date', 'amount'] as $k)@if ($errors->has($k))<p class="error">{{ $errors->first($k) }}</p>@endif @endforeach

    <div class="row2">
        <div>
            <label for="customer_id" style="margin-top:0">Customer *</label>
            @if ($editing)<input value="{{ $payment->customer_name }}" disabled><p class="muted" style="font-size:13px;margin:4px 0 0">A payment from the wrong customer is voided and entered again.</p>
            @else<select id="customer_id" name="customer_id" required><option value="">Choose a customer...</option>@foreach ($customers as $c)<option value="{{ $c->id }}" @selected((string) old('customer_id', $selectedCustomer) === (string) $c->id)>{{ $c->name }}</option>@endforeach</select>@endif
        </div>
        <div><label for="ledger_id" style="margin-top:0">Deposit to *</label>
            <select id="ledger_id" name="ledger_id" required><option value="">Choose a bank or cash account...</option>@foreach ($banks as $a)<option value="{{ $a['v'] }}" @disabled($a['g']) @selected((string) $v('ledger_id') === (string) $a['v'])>{{ str_repeat("\u{00A0}\u{00A0}\u{00A0}", $a['d']).$a['t'] }}</option>@endforeach</select></div>
    </div>
    <div class="row2" style="grid-template-columns:1fr 1fr 1fr">
        <div><label for="date">Date *</label><input id="date" type="date" name="date" required value="{{ old('date', $editing ? substr($payment->date, 0, 10) : now()->format('Y-m-d')) }}"></div>
        <div><label for="amount">Amount *</label><input id="amount" name="amount" inputmode="decimal" required value="{{ old('amount', $editing ? $payment->amount : $amount) }}" placeholder="0.00"></div>
        <div><label for="number">Payment no.</label><input id="number" name="number" inputmode="numeric" value="{{ old('number', $editing ? $payment->number : '') }}" placeholder="{{ $nextNumber ? 'Next: '.$nextNumber : '' }}"></div>
    </div>
    @if ($editing && Money::positive($used ?? 0))<p class="muted" style="font-size:13px">{{ Money::format($used) }} of this payment is applied to invoices or refunded, so the amount can't go below that.</p>@endif

    @if ($wht)
        <label style="font-weight:400"><input type="checkbox" name="wht_on" value="1" id="whtOn" @checked(old('wht_on', $hadWht))> The customer withheld tax on this payment</label>
        <div id="whtBox" @unless (old('wht_on', $hadWht)) hidden @endunless style="margin-top:6px"><label for="wht_rate" style="margin-top:0">Tax withheld %</label>
            <input id="wht_rate" name="wht_rate" inputmode="decimal" style="width:120px" value="{{ old('wht_rate', $hadWht ? rtrim(rtrim((string) $payment->wht_rate, '0'), '.') : '3') }}">
            <span class="muted" style="font-size:13px">The payment still settles the full amount; the tax goes to Withholding Tax Expense and only the rest reaches the bank.</span></div>
    @endif

    <div class="row2" style="grid-template-columns:1fr 1fr 1fr">
        <div><label for="method">Method</label><select id="method" name="method"><option value="">—</option>@foreach ($methods as $m)<option value="{{ $m }}" @selected((string) $v('method') === (string) $m)>{{ $m }}</option>@endforeach</select></div>
        <div><label for="reference">Reference</label><input id="reference" name="reference" maxlength="100" value="{{ $v('reference') }}"></div>
        <div><label for="notes">Notes</label><input id="notes" name="notes" maxlength="500" value="{{ $v('notes') }}"></div>
    </div>

    @unless ($editing)
    <div id="applyBox" hidden>
        <h2>Apply to invoices</h2>
        <p class="muted" style="margin-top:0;font-size:13px">Tick the invoices this payment pays. Whatever is not applied stays on the customer as credit. <button type="button" class="ghost sm" id="fifo">Apply oldest first</button></p>
        <div class="tbl"><table><thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th class="num">Total</th><th class="num">Balance due</th><th class="num" style="width:130px">Apply</th></tr></thead><tbody id="docs"></tbody>
            <tfoot><tr><td colspan="5" class="num">Applied</td><td class="num"><strong id="appliedTotal">0.00</strong></td></tr></tfoot></table></div>
        <p class="muted" id="creditNote" style="font-size:13px"></p>
    </div>
    @endunless

    <div class="actions" style="margin-top:20px"><button>{{ $editing ? 'Save changes' : 'Receive payment' }}</button>@unless ($editing)<button name="save_and_new" value="1" class="ghost">Save &amp; new</button>@endunless<a class="btn ghost" href="{{ $editing ? route('payments.show', $payment->id) : route('payments.index') }}">Cancel</a></div>
</form>

<script>
(function () {
    var wht = document.getElementById('whtOn'); if (wht) wht.addEventListener('change', function () { document.getElementById('whtBox').hidden = !wht.checked; });
    var cust = document.getElementById('customer_id'); if (!cust) return;
    var tbody = document.getElementById('docs'), box = document.getElementById('applyBox'), amount = document.getElementById('amount'), focus = {{ (int) $focus }};
    function num(v) { v = String(v).replace(/[, ]/g, ''); return v === '' || isNaN(v) ? 0 : parseFloat(v); }
    function fmt(x) { return x.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
    function total() { var t = 0; tbody.querySelectorAll('input').forEach(function (i) { t += num(i.value); }); document.getElementById('appliedTotal').textContent = fmt(t); return t; }
    function fifo() { var left = num(amount.value); tbody.querySelectorAll('tr').forEach(function (r) { var i = r.querySelector('input'), due = parseFloat(i.dataset.due), take = Math.max(0, Math.min(left, due)); i.value = take > 0 ? take.toFixed(2) : ''; left -= take; }); total(); }
    function load() {
        tbody.innerHTML = ''; if (!cust.value) { box.hidden = true; return; }
        fetch('{{ url('/payments/open-invoices') }}/' + cust.value, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            box.hidden = !j.docs.length && !(parseFloat(j.credit) > 0);
            tbody.innerHTML = j.docs.map(function (d) {
                return '<tr><td>' + esc(d.label) + '</td><td>' + esc(d.date) + '</td><td' + (d.overdue ? ' style="color:var(--danger)"' : '') + '>' + esc(d.due || '—') + '</td><td class="num">' + fmt(parseFloat(d.total)) + '</td><td class="num">' + fmt(parseFloat(d.balance_due)) +
                    '</td><td><input class="num" name="apply[' + d.id + ']" inputmode="decimal" data-due="' + d.balance_due + '" data-id="' + d.id + '"></td></tr>';
            }).join('');
            tbody.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', total); });
            document.getElementById('creditNote').textContent = parseFloat(j.credit) > 0 ? 'This customer also has ' + fmt(parseFloat(j.credit)) + ' of unapplied credit from earlier payments.' : '';
            if (focus) { var f = tbody.querySelector('input[data-id="' + focus + '"]'); if (f) { f.value = Math.min(num(amount.value) || parseFloat(f.dataset.due), parseFloat(f.dataset.due)).toFixed(2); } } else if (num(amount.value)) fifo();
            total();
        });
    }
    cust.addEventListener('change', load); document.getElementById('fifo').addEventListener('click', fifo); amount.addEventListener('change', function () { if (!focus) fifo(); });
    load();
})();
</script>
@endsection
