{{-- Cash refund of what is left on a credit note or an overpayment. Needs $action, $remaining, $heading, $ui, $banks, $methods --}}
<h2>{{ $heading }}</h2>
<form method="POST" action="{{ $action }}" class="card" style="max-width:700px" onsubmit="return confirm('Record this refund? It posts to the books.')">
    @csrf
    @foreach (['refund_amount', 'refund_ledger_id', 'refund_date'] as $k)@if ($errors->has($k))<p class="error">{{ $errors->first($k) }}</p>@endif @endforeach
    <div class="row2">
        <div><label for="refund_ledger_id" style="margin-top:0">{{ $ui['side'] === 'ar' ? 'Paid from' : 'Paid into' }} *</label>
            <select id="refund_ledger_id" name="refund_ledger_id" required><option value="">Choose a bank or cash account...</option>@foreach ($banks as $a)<option value="{{ $a['v'] }}" @disabled($a['g']) @selected((string) old('refund_ledger_id') === (string) $a['v'])>{{ str_repeat("\u{00A0}\u{00A0}\u{00A0}", $a['d']).$a['t'] }}</option>@endforeach</select></div>
        <div><label for="refund_amount" style="margin-top:0">Amount * <span class="muted">(up to {{ \App\Support\Money::format($remaining) }})</span></label><input id="refund_amount" name="refund_amount" inputmode="decimal" required value="{{ old('refund_amount', $remaining) }}"></div>
    </div>
    <div class="row2" style="grid-template-columns:1fr 1fr 1fr">
        <div><label for="refund_date">Date *</label><input id="refund_date" type="date" name="refund_date" required value="{{ old('refund_date', now()->format('Y-m-d')) }}"></div>
        <div><label for="refund_method">Method</label><select id="refund_method" name="refund_method"><option value="">—</option>@foreach ($methods as $m)<option value="{{ $m }}" @selected(old('refund_method') === $m)>{{ $m }}</option>@endforeach</select></div>
        <div><label for="refund_reference">Reference</label><input id="refund_reference" name="refund_reference" maxlength="100" value="{{ old('refund_reference') }}"></div>
    </div>
    <div class="actions" style="margin-top:16px"><button>Record refund</button></div>
</form>
