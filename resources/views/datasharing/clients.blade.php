@extends($exporting ? 'datasharing.bare' : 'layouts.main')
@section('title', 'Data Sharing - Clients')
@section('content')
@php
    use App\Support\DsView as V;
    $m = fn ($v) => V::m($v); $d = fn ($v) => V::d($v);
    $fNames = ['all' => 'All', 'paid' => 'Paid', 'unpaid' => 'Unpaid', 'overdue' => 'Overdue', 'recharged' => 'Recharged'];
    $count = ['all' => count($cd['all'])];
    foreach (['paid', 'unpaid', 'overdue'] as $k) { $count[$k] = count(array_filter($cd['all'], fn ($x) => $x['status'] === $k)); }
    $count['recharged'] = count(array_filter($cd['all'], fn ($x) => $x['rech_n'] > 0));
    $keep = fn () => array_filter(['q' => $q, 'sim' => request()->query('sim'), 'plan' => request()->query('plan'), 'due' => request()->query('due')]);
    $url = fn ($ff) => route('datasharing.clients', array_filter(['f' => $ff] + $keep()));
    $qs = array_filter(['f' => $f] + $keep());
@endphp
@include('datasharing._style')
<section class="ds">
    @unless ($exporting)
    <div class="ds-ph"><div><h1>Clients</h1><p>Everyone on a slot, sorted by when they pay next</p></div>
        <div class="ds-tools">@include('datasharing._export', ['route' => 'datasharing.clients', 'qs' => $qs])</div></div>
    @include('datasharing._top')
    <div class="ds-seg" role="group" aria-label="Show">@foreach ($fNames as $k => $nm)<a href="{{ $url($k) }}" class="{{ $f === $k ? 'on' : '' }}">{{ $nm }}<small>{{ (int) $count[$k] }}</small></a>@endforeach</div>
    @include('datasharing._filters', ['fltAction' => 'datasharing.clients', 'fltFields' => ['sim', 'plan', 'due', 'q'], 'fltKeep' => ['f' => $f], 'fltQHint' => 'Name, data number, WhatsApp or SIM'])
    @endunless
    <div class="ds-card"><div class="ds-tw">
        <table class="ds-table">
            <thead><tr><th>Client</th><th>Data number</th><th>WhatsApp</th><th>SIM</th><th>Plan</th><th class="r">Used</th><th class="r">Left</th><th class="r">Price</th><th>Status</th><th>Due</th><th class="r">Recharges</th><th class="r">Paid this cycle</th>@unless ($exporting)<th></th>@endunless</tr></thead>
            <tbody>
            @foreach ($rows as $x)
                <tr><td><a href="{{ route('datasharing.client', $x['id']) }}"><b>{{ $x['name'] }}</b></a>{{ $exporting ? (! empty($x['main']) ? ' (main contact)' : '') : V::star($x) }}</td><td>{{ $x['phone'] }}</td><td>{{ $x['whatsapp'] !== '' ? $x['whatsapp'] : $x['phone'] }}</td>
                    <td>{{ $x['sim'] }}@if (! empty($x['mc']))<br><small class="ds-hint">main contact {{ $x['mc'] }}</small>@endif</td><td>{{ (int) $x['gb'] }} GB</td>
                    <td class="r">{{ isset($x['used']) && $x['used'] !== null ? V::gbf($x['used']).' GB' : '–' }}</td><td class="r">{{ isset($x['left']) && $x['left'] !== null ? V::gbf($x['left']).' GB' : '–' }}</td><td class="r">{{ $m($x['price']) }}</td>
                    <td>{{ $exporting ? V::STATUS[$x['status']] : V::pill($x) }}</td><td>{{ $d($x['next']) }}</td><td class="r">{{ $x['rech_n'] ?: '–' }}</td><td class="r">{{ $m($x['income']) }}</td>
                    @unless ($exporting)<td class="r">{{ V::payButton($x, 'Pay') }} {{ V::waButton(V::waMsg($x, $company), $x['name'], V::wa($x)) }}</td>@endunless</tr>
            @endforeach
            @if (! $rows)<tr><td colspan="13" class="ds-empty">No client matches.</td></tr>@endif
            </tbody>
            @if ($rows)<tfoot><tr><td colspan="7">{{ count($rows) }} client{{ count($rows) === 1 ? '' : 's' }}</td><td class="r">{{ $m(array_sum(array_column($rows, 'price'))) }}</td><td colspan="3"></td><td class="r">{{ $m(array_sum(array_column($rows, 'income'))) }}</td>@unless ($exporting)<td></td>@endunless</tr></tfoot>@endif
        </table>
    </div></div>
</section>
@include('datasharing._modals')
@endsection
