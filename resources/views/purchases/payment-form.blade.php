@extends('layouts.main')
@php
    use App\Support\Money;
    $editing = (bool) $payment;
    $v = fn ($f, $default = '') => old($f, $payment->{$f} ?? $default);
@endphp
@section('title', $editing ? 'Edit payment' : 'Pay vendor')
@section('content')
@include('purchases._tabs')
<div class="bar"><h1 style="margin:0">{{ $editing ? 'Edit payment '.\App\Services\Sales\Allocations::docLabel('', $payment->number) : 'Pay vendor' }}</h1><a class="muted" href="{{ $editing ? route('vendor-payments.show', $payment->id) : route('vendor-payments.index') }}">&larr; Back</a></div>

<form class="card" method="POST" action="{{ $editing ? route('vendor-payments.update', $payment->id) : route('vendor-payments.store') }}" style="max-width:860px">
    @csrf
    @if ($editing) @method('PUT') @endif
    @foreach (['number', 'date', 'amount'] as $k)@if ($errors->has($k))<p class="error">{{ $errors->first($k) }}</p>@endif @endforeach
    <div class="row2">
        <div><label for="vendor_id" style="margin-top:0">Vendor *</label>
            @if ($editing)<input value="{{ $payment->vendor_name }}" disabled>
            @else<select id="vendor_id" name="vendor_id" required><option value="">Choose a vendor...</option>@foreach ($vendors as $c)<option value="{{ $c->id }}" @selected((string) old('vendor_id', $selectedVendor) === (string) $c->id)>{{ $c->name }}</option>@endforeach</select>@endif</div>
        <div><label for="ledger_id" style="margin-top:0">Pay from *</label>
            <select id="ledger_id" name="ledger_id" required><option value="">Choose a bank or cash account...</option>@foreach ($banks as $a)<option value="{{ $a['v'] }}" @disabled($a['g']) @selected((string) $v('ledger_id') === (string) $a['v'])>{{ str_repeat("\u{00A0}\u{00A0}\u{00A0}", $a['d']).$a['t'] }}</option>@endforeach</select></div>
    </div>
    <p class="muted" id="directNote" style="font-size:13px;margin:6px 0 0" hidden></p>
    <div class="row2" style="grid-template-columns:1fr 1fr 1fr">
        <div><label for="date">Date *</label><input id="date" type="date" name="date" required value="{{ old('date', $editing ? substr($payment->date, 0, 10) : now()->format('Y-m-d')) }}"></div>
        <div><label for="amount">Amount *</label><input id="amount" name="amount" inputmode="decimal" required value="{{ old('amount', $editing ? $payment->amount : $amount) }}" placeholder="0.00"></div>
        <div><label for="number">Payment no.</label><input id="number" name="number" inputmode="numeric" value="{{ old('number', $editing ? $payment->number : '') }}" placeholder="{{ $nextNumber ? 'Next: '.$nextNumber : '' }}"></div>
    </div>
    @if ($editing && Money::positive($used ?? 0))<p class="muted" style="font-size:13px">{{ Money::format($used) }} of this payment is applied to bills or refunded, so the amount can't go below that.</p>@endif
    <div class="row2" style="grid-template-columns:1fr 1fr 1fr">
        <div><label for="method">Method</label><select id="method" name="method"><option value="">—</option>@foreach ($methods as $m)<option value="{{ $m }}" @selected((string) $v('method') === (string) $m)>{{ $m }}</option>@endforeach</select></div>
        <div><label for="reference">Reference</label><input id="reference" name="reference" maxlength="100" value="{{ $v('reference') }}"></div>
        <div><label for="notes">Notes</label><input id="notes" name="notes" maxlength="500" value="{{ $v('notes') }}"></div>
    </div>

    @unless ($editing)
    <div id="applyBox" hidden>
        <h2>Apply to bills</h2>
        <p class="muted" style="margin-top:0;font-size:13px">Fill in the bills this payment pays. Whatever is not applied stays as a prepayment to the vendor. <button type="button" class="ghost sm" id="fifo">Apply oldest first</button></p>
        <div class="tbl"><table><thead><tr><th>Bill</th><th>Date</th><th>Due</th><th class="num">Total</th><th class="num">Balance due</th><th class="num" style="width:130px">Apply</th></tr></thead><tbody id="docs"></tbody>
            <tfoot><tr><td colspan="5" class="num">Applied</td><td class="num"><strong id="appliedTotal">0.00</strong></td></tr></tfoot></table></div>
        <p class="muted" id="creditNote" style="font-size:13px"></p>
    </div>
    @endunless

    <div class="actions" style="margin-top:20px"><button>{{ $editing ? 'Save changes' : 'Record payment' }}</button>@unless ($editing)<button name="save_and_new" value="1" class="ghost">Save &amp; new</button>@endunless<a class="btn ghost" href="{{ $editing ? route('vendor-payments.show', $payment->id) : route('vendor-payments.index') }}">Cancel</a></div>
</form>

@unless ($editing)
<script>
(function () {
    var vend = document.getElementById('vendor_id'), tbody = document.getElementById('docs'), box = document.getElementById('applyBox'), amount = document.getElementById('amount'), focus = {{ (int) $focus }}, direct = @json($direct);
    function num(v) { v = String(v).replace(/[, ]/g, ''); return v === '' || isNaN(v) ? 0 : parseFloat(v); }
    function fmt(x) { return x.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }
    function total() { var t = 0; tbody.querySelectorAll('input').forEach(function (i) { t += num(i.value); }); document.getElementById('appliedTotal').textContent = fmt(t); var n = document.getElementById('directNote'); n.hidden = !(direct[vend.value] && t === 0); if (!n.hidden) n.textContent = 'This vendor has a default expense account, so a payment that is not applied to a bill is posted straight to "' + direct[vend.value] + '" instead of Accounts Payable.'; }
    function fifo() { var left = num(amount.value); tbody.querySelectorAll('tr').forEach(function (r) { var i = r.querySelector('input'), due = parseFloat(i.dataset.due), take = Math.max(0, Math.min(left, due)); i.value = take > 0 ? take.toFixed(2) : ''; left -= take; }); total(); }
    function load() {
        tbody.innerHTML = ''; if (!vend.value) { box.hidden = true; total(); return; }
        fetch('{{ url('/vendor-payments/open-bills') }}/' + vend.value, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            box.hidden = !j.docs.length && !(parseFloat(j.credit) > 0);
            tbody.innerHTML = j.docs.map(function (d) { return '<tr><td>' + esc(d.label) + '</td><td>' + esc(d.date) + '</td><td' + (d.overdue ? ' style="color:var(--danger)"' : '') + '>' + esc(d.due || '—') + '</td><td class="num">' + fmt(parseFloat(d.total)) + '</td><td class="num">' + fmt(parseFloat(d.balance_due)) + '</td><td><input class="num" name="apply[' + d.id + ']" inputmode="decimal" data-due="' + d.balance_due + '" data-id="' + d.id + '"></td></tr>'; }).join('');
            tbody.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', total); });
            document.getElementById('creditNote').textContent = parseFloat(j.credit) > 0 ? 'This vendor also has ' + fmt(parseFloat(j.credit)) + ' of unapplied prepayments and credits.' : '';
            if (focus) { var f = tbody.querySelector('input[data-id="' + focus + '"]'); if (f) f.value = Math.min(num(amount.value) || parseFloat(f.dataset.due), parseFloat(f.dataset.due)).toFixed(2); } else if (num(amount.value)) fifo();
            total();
        });
    }
    vend.addEventListener('change', load); document.getElementById('fifo').addEventListener('click', fifo); amount.addEventListener('change', function () { if (!focus) fifo(); });
    load();
})();
</script>
@endunless
@endsection
