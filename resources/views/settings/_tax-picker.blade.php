{{-- A pick-list of the company's named tax rates (Settings → Tax rates) that fills the tax % box (#tp). $side: 'ar' (sales) or 'ap' (purchases); $isNew: pre-pick the default rate on a new document. --}}
@php
    $__rates = \Illuminate\Support\Facades\DB::connection('tenant')->table('tax_rates')->where($side === 'ap' ? 'on_purchases' : 'on_sales', 1)->orderBy('rate')->orderBy('name')->get();
@endphp
@if ($__rates->isNotEmpty())
    <select id="taxRatePick" style="width:auto;display:inline-block" title="Pick a saved tax rate">
        <option value="">Tax rate...</option>
        @foreach ($__rates as $r)<option value="{{ \App\Services\Settings\SettingsService::pct($r->rate) }}" data-default="{{ $r->is_default ? 1 : 0 }}">{{ $r->name }} ({{ \App\Services\Settings\SettingsService::pct($r->rate) }}%)</option>@endforeach
    </select>
    <script>
    (function () {
        var pick = document.getElementById('taxRatePick'), tp = document.getElementById('tp'), tt = document.getElementById('tt');
        function apply(v) { tp.value = v; tp.dispatchEvent(new Event('input', { bubbles: true })); tp.dispatchEvent(new Event('change', { bubbles: true })); }
        pick.addEventListener('change', function () { if (pick.value !== '') { apply(pick.value); } });
        @if ($isNew)
        window.addEventListener('load', function () {
            var d = pick.querySelector('option[data-default="1"]');
            if (d && tp.value.trim() === '' && (!tt || tt.value.trim() === '')) { pick.value = d.value; apply(d.value); }
        });
        @endif
    })();
    </script>
@endif
