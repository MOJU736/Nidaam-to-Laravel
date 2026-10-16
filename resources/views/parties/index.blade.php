@extends('layouts.main')
@section('title', $cfg['many'])
@section('content')
@php
    use App\Support\Money;
    $isAr = $cfg['side'] === 'ar';
    $overdueIds = array_flip($stats['overdue_ids']);
    $soonIds = array_flip($stats['due_soon_ids']);
    $unbilledIds = array_flip($stats['unbilled_ids'] ?? []);
    $tiles = [
        ['all', $stats['total'], $stats['total'] === 1 ? $cfg['one'] : $cfg['many']],
        ['outstanding', Money::format($stats['outstanding']), $isAr ? 'Total outstanding' : 'We owe'],
        ['overdue', Money::format($stats['overdue']), 'Overdue'],
    ];
    if (! $isAr) { $tiles[] = ['soon', Money::format($stats['due_soon']), 'Due in 7 days']; }
    $tiles[] = ['credit', $stats['credit'], 'With a credit balance'];
    $tiles[] = ['paid', $stats['paid'], 'Paid in full'];
    if (isset($stats['unbilled'])) { $tiles[] = ['unbilled', $stats['unbilled'], $stats['unbilled'] === 1 ? 'Unbilled transaction' : 'Unbilled transactions']; }
@endphp

<div class="bar">
    <div><h1>{{ $cfg['many'] }}</h1><div class="muted">{{ $isAr ? 'People and companies you sell to' : 'Who you buy from' }}</div></div>
    <a class="btn" href="{{ route($cfg['route'].'.create') }}">{{ \App\Support\Icon::svg('plus', 16) }}Add {{ $cfg['one'] }}</a>
</div>
<div class="tools"><div></div><input id="q" class="find" type="search" placeholder="Search {{ strtolower($cfg['many']) }}"></div>

<div class="stats" id="tiles">
    @foreach ($tiles as [$key, $value, $label])
        <button type="button" data-filter="{{ $key }}" @class(['on' => $key === 'all'])><b>{{ $value }}</b><span>{{ $label }}</span></button>
    @endforeach
</div>

<div class="card tbl">
    <table id="list">
        <thead><tr><th>Name</th><th>Contact</th><th>Email / Phone</th><th class="num">Balance</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse ($parties as $p)
            @php
                $b = $p->balance;
                $tags = [];
                if (Money::isZero($b['amount'])) { $tags[] = 'paid'; }
                elseif ($b['dc'] === $cfg['normal']) {
                    $tags[] = 'outstanding';
                    if (isset($overdueIds[$p->id])) { $tags[] = 'overdue'; }
                    if (isset($soonIds[$p->id])) { $tags[] = 'soon'; }
                } else { $tags[] = 'credit'; }
                if (isset($unbilledIds[$p->id])) { $tags[] = 'unbilled'; }
                $signed = Money::signed($b, $cfg['normal']);
                $chip = in_array('overdue', $tags) ? ['overdue', 'Overdue'] : (in_array('outstanding', $tags) ? ['owes', $isAr ? 'Owes you' : 'You owe'] : (in_array('credit', $tags) ? ['', 'In credit'] : ['', 'Paid up']));
            @endphp
            <tr data-tags="{{ implode(' ', $tags) }}" data-text="{{ strtolower($p->name.' '.$p->contact_person.' '.$p->email.' '.$p->phone) }}">
                <td><a class="who" href="{{ route($cfg['route'].'.show', $p->id) }}"><span class="avs">{{ mb_strtoupper(mb_substr(trim($p->name), 0, 1)) }}</span><strong>{{ $p->name }}</strong></a></td>
                <td>{{ $p->contact_person }}</td>
                <td>@if ($p->email)<div>{{ $p->email }}</div>@endif @if ($p->phone)<div>{{ $p->phone }}</div>@endif</td>
                <td class="num">{{ Money::format($signed) }}</td>
                <td><span class="pill {{ $chip[0] }}">{{ $chip[1] }}</span>@unless ($p->status) <span class="pill">Inactive</span>@endunless</td>
                <td>
                    <div class="actions">
                        @if ($isAr)<a class="btn ghost sm" href="{{ route('invoices.create', $p->id) }}">Invoice</a><a class="btn ghost sm" href="{{ route('payments.create', $p->id) }}">Receive payment</a>@else<a class="btn ghost sm" href="{{ route('bills.create', $p->id) }}">Bill</a><a class="btn ghost sm" href="{{ route('vendor-payments.create', $p->id) }}">Pay</a>@endif
                        <a class="btn ghost sm" href="{{ route($cfg['route'].'.edit', $p->id) }}">Edit</a>
                        <form class="inline" method="POST" action="{{ route($cfg['route'].'.destroy', $p->id) }}" onsubmit="return confirm('Delete this {{ strtolower($cfg['one']) }}?')">
                            @csrf @method('DELETE')<button class="danger sm">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted" style="text-align:center;padding:24px">No {{ strtolower($cfg['many']) }} yet. <a href="{{ route($cfg['route'].'.create') }}">Add your first {{ strtolower($cfg['one']) }}</a>.</td></tr>
        @endforelse
        <tr id="none" style="display:none"><td colspan="6" class="muted" style="text-align:center;padding:24px">No {{ strtolower($cfg['many']) }} match this filter.</td></tr>
        </tbody>
    </table>
</div>

<script>
(function () {
    var filter = 'all', rows = [].slice.call(document.querySelectorAll('#list tbody tr[data-tags]'));
    var q = document.getElementById('q'), none = document.getElementById('none');
    function apply() {
        var term = q.value.trim().toLowerCase(), shown = 0;
        rows.forEach(function (r) {
            var ok = (filter === 'all' || r.dataset.tags.split(' ').indexOf(filter) > -1) && (!term || r.dataset.text.indexOf(term) > -1);
            r.style.display = ok ? '' : 'none'; if (ok) shown++;
        });
        none.style.display = rows.length && !shown ? '' : 'none';
    }
    document.querySelectorAll('#tiles button').forEach(function (b) {
        b.addEventListener('click', function () {
            filter = b.dataset.filter;
            document.querySelectorAll('#tiles button').forEach(function (x) { x.classList.toggle('on', x === b); });
            apply();
        });
    });
    q.addEventListener('input', apply);
})();
</script>
@endsection
