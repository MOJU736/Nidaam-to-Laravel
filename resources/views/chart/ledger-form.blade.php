@extends('layouts.main')
@section('title', $ledger ? 'Edit account' : 'New account')
@section('content')
@php
    use App\Support\Money;
    $v = fn ($f, $d = '') => old($f, $ledger->{$f} ?? $d);
    $editing = (bool) $ledger;
    $lockedOb = $booksClosed && $editing;
    $opAmount = old('op_balance', $editing && Money::cmp($ledger->op_balance, 0) !== 0 ? $ledger->op_balance : '');
    $descriptions = [
        'Asset' => 'Assets are what the business owns or is owed: cash, bank, stock, equipment, money customers owe.',
        'Liability' => 'Liabilities are what the business owes: vendors, loans, taxes to pay.',
        'Equity' => "Equity is the owners' share: capital put in, profits kept, drawings.",
        'Income' => 'Income is money earned: sales, services, rent received, interest.',
        'Expense' => 'Expenses are the costs of running the business: rent, salaries, fuel, bank charges.',
    ];
@endphp
<a class="back" href="{{ route('chart') }}">&larr; Chart of Accounts</a>
<div class="bar"><h1>{{ $editing ? 'Edit account' : 'New account' }}</h1></div>

<div class="row2" style="grid-template-columns:{{ $editing ? '2fr 1fr' : '1fr' }};align-items:start">
<form class="card" method="POST" action="{{ $editing ? route('ledgers.update', $ledger->id) : route('ledgers.store') }}">
    @csrf
    @if ($editing) @method('PUT') @endif
    @foreach ($errors->all() as $e)<p class="error">{{ $e }}</p>@endforeach

    <div class="row2">
        <div>
            <label for="name">Account name *</label>
            <input id="name" name="name" value="{{ $v('name') }}" maxlength="255" placeholder="e.g. Office rent" required @unless($editing) autofocus @endunless>
        </div>
        <div>
            <label for="group_id">Group *</label>
            <select id="group_id" name="group_id" required>
                <option value="">Choose a group...</option>
                @foreach ($groups as $gid => $g)
                    <option value="{{ $gid }}" data-type="{{ $g['type'] }}" @selected((int) old('group_id', $picked) === $gid)>{{ str_repeat('— ', $g['depth']).$g['label'] }}</option>
                @endforeach
            </select>
            <p class="muted" id="typeNote" style="margin:4px 0 0;font-size:13px"></p>
        </div>
    </div>

    <h2>Opening balance <span class="muted" style="font-weight:400;font-size:13px">(optional)</span></h2>
    @if ($lockedOb)<p class="muted">The books are closed, so the opening balance can't change. An administrator can change the close date under Settings.</p>@endif
    <div class="row2">
        <div><label for="op_balance">Amount</label><input id="op_balance" name="op_balance" inputmode="decimal" value="{{ $opAmount }}" placeholder="0.00" @readonly($lockedOb)></div>
        <div>
            <label for="op_balance_dc">Side</label>
            <select id="op_balance_dc" name="op_balance_dc" @disabled($lockedOb)>
                <option value="D" @selected(old('op_balance_dc', $ledger->op_balance_dc ?? 'D') === 'D')>Debit</option>
                <option value="C" @selected(old('op_balance_dc', $ledger->op_balance_dc ?? 'D') === 'C')>Credit</option>
            </select>
            @if ($lockedOb)<input type="hidden" name="op_balance_dc" value="{{ $ledger->op_balance_dc }}">@endif
        </div>
    </div>
    <p class="muted" style="font-size:13px">What the account held on the day you started using Nidaam. <span id="sideHint"></span></p>

    <h2>Options</h2>
    <label style="font-weight:400"><input type="checkbox" name="type" value="1" @checked(old('type', $ledger->type ?? 0))> <strong>Bank or cash account</strong> — money can be received into it and paid out of it</label>
    <label style="font-weight:400"><input type="checkbox" name="reconciliation" value="1" @checked(old('reconciliation', $ledger->reconciliation ?? 0))> <strong>Reconcile with statements</strong> — tick off its transactions against each bank statement</label>
    <label style="font-weight:400"><input type="checkbox" name="is_contra" value="1" @checked(old('is_contra', $ledger->is_contra ?? 0))> <strong>Contra account</strong> — reduces the other accounts in its group, so its balance runs the other way</label>

    <label for="notes">Notes <span class="muted">(optional)</span></label>
    <textarea id="notes" name="notes" rows="2" maxlength="500">{{ $v('notes') }}</textarea>

    <div class="actions" style="margin-top:20px">
        <button type="submit">{{ $editing ? 'Save changes' : 'Create account' }}</button>
        @unless ($editing)<button type="submit" name="save_and_new" value="1" class="ghost">Save &amp; add another</button>@endunless
        <a class="btn ghost" href="{{ route('chart', $editing ? ['show' => $ledger->id] : []) }}">Cancel</a>
    </div>
</form>

@if ($editing)
    @php $bal = $closing['dc'] === $normal ? $closing['amount'] : Money::neg($closing['amount']); @endphp
    <div class="card">
        <div class="muted">Balance today</div>
        <div style="font-size:24px;font-weight:700;{{ Money::cmp($bal, 0) < 0 ? 'color:var(--danger)' : '' }}">{{ Money::format($bal) }}</div>
        <dl class="kv" style="grid-template-columns:100px 1fr;margin-top:12px">
            <dt>Code</dt><dd>{{ $ledger->code ?: '—' }}</dd>
            <dt>Transactions</dt><dd>{{ number_format($lines) }} line{{ $lines === 1 ? '' : 's' }}</dd>
            @if ($party)<dt>Belongs to</dt><dd>A {{ $party }}'s own account</dd>@endif
        </dl>
        @if ($lines)
            <p class="muted" style="font-size:13px">An account with transactions can't be deleted.</p>
        @elseif (! $party)
            <form method="POST" action="{{ route('ledgers.destroy', $ledger->id) }}" onsubmit="return confirm('Delete this account?')" style="margin-top:12px">@csrf @method('DELETE')<button class="danger sm">Delete this account</button></form>
        @endif
    </div>
@endif
</div>

<script>
(function () {
    var notes = @json($descriptions), g = document.getElementById('group_id'), n = document.getElementById('typeNote'), dc = document.getElementById('op_balance_dc'), h = document.getElementById('sideHint');
    var normal = { Asset: 'debit', Expense: 'debit', Liability: 'credit', Equity: 'credit', Income: 'credit' };
    function sync() {
        var t = g.selectedOptions[0] && g.selectedOptions[0].dataset.type;
        n.textContent = t ? notes[t] : '';
        h.textContent = t ? 'Accounts of this type normally have a ' + normal[t] + ' balance.' : '';
    }
    g.addEventListener('change', sync); sync();
})();
</script>
@endsection
