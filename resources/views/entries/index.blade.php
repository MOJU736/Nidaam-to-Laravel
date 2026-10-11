@extends('layouts.main')
@section('title', 'Journal Entries')
@section('content')
@php
    use App\Support\Money;
    $qs = fn (array $over = []) => array_filter(array_merge($filter, ['page' => null], $over), fn ($v) => $v !== '' && $v !== null);
    $advanced = $filter['account'] || $filter['tag'] || $filter['min'] !== '' || $filter['max'] !== '' || $filter['all'];
@endphp
<div class="bar">
    <h1 style="margin:0">Journal Entries</h1>
    <div class="actions">
        <a class="btn ghost" href="{{ route('entries.simple', 'in') }}">Money In</a>
        <a class="btn ghost" href="{{ route('entries.simple', 'out') }}">Money Out</a>
        <a class="btn ghost" href="{{ route('entries.transfer') }}">Transfer</a>
        <a class="btn" href="{{ route('entries.create', 'journal') }}">+ Journal</a>
        @foreach ($quickTypes as $qt)
            @if ($qt->label !== 'journal')<a class="btn ghost" href="{{ route('entries.create', $qt->label) }}">+ {{ $qt->name }}</a>@endif
        @endforeach
    </div>
</div>

<form method="GET" class="card" style="margin-bottom:16px">
    <div class="row2" style="grid-template-columns:2fr 1fr 1fr 1fr">
        <div><label for="q" style="margin-top:0">Search</label><input id="q" name="q" value="{{ $filter['q'] }}" placeholder="Account, description, notes or number"></div>
        <div><label for="type" style="margin-top:0">Type</label>
            <select id="type" name="type"><option value="">All types</option>@foreach ($typeNames as $k => $n)<option value="{{ $k }}" @selected($filter['type'] === $k)>{{ $n }}</option>@endforeach</select></div>
        <div><label for="from" style="margin-top:0">From</label><input id="from" type="date" name="from" value="{{ $filter['from'] }}"></div>
        <div><label for="to" style="margin-top:0">To</label><input id="to" type="date" name="to" value="{{ $filter['to'] }}"></div>
    </div>
    <details @if ($advanced) open @endif style="margin-top:10px">
        <summary class="muted" style="cursor:pointer">More filters</summary>
        <div class="row2" style="grid-template-columns:2fr 1fr 1fr 1fr;margin-top:6px">
            <div><label for="account">Account</label>
                <select id="account" name="account"><option value="">Any account</option>@foreach ($accounts as $a)<option value="{{ $a->id }}" @selected((string) $filter['account'] === (string) $a->id)>{{ $a->name }}</option>@endforeach</select></div>
            <div><label for="tag">Tag</label>
                <select id="tag" name="tag"><option value="">Any tag</option>@foreach ($tags as $t)<option value="{{ $t->id }}" @selected((string) $filter['tag'] === (string) $t->id)>{{ $t->title }}</option>@endforeach</select></div>
            <div><label for="min">Amount from</label><input id="min" name="min" inputmode="decimal" value="{{ $filter['min'] }}"></div>
            <div><label for="max">Amount to</label><input id="max" name="max" inputmode="decimal" value="{{ $filter['max'] }}"></div>
        </div>
        <label style="font-weight:400"><input type="checkbox" name="all" value="1" @checked($filter['all'])> Include entries made by invoices, bills, payments and other screens</label>
    </details>
    <div class="actions" style="margin-top:12px"><button class="sm">Filter</button><a class="btn ghost sm" href="{{ route('entries.index') }}">Clear</a><span class="muted" style="align-self:center">{{ number_format($total) }} entr{{ $total === 1 ? 'y' : 'ies' }}</span></div>
</form>

<div class="card tbl">
    <table>
        <thead><tr><th>Date</th><th>No.</th><th>Type</th><th>From → To</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody>
        @forelse ($rows as $e)
            @php
                $num = $e->number === null ? '—' : $e->prefix.($e->zero_padding > 0 ? str_pad($e->number, $e->zero_padding, '0', STR_PAD_LEFT) : $e->number).$e->suffix;
                $s = $sides[$e->id] ?? [];
            @endphp
            <tr>
                <td>{{ Money::date($e->date) }}</td>
                <td><a href="{{ route('entries.show', [$e->type_label, $e->id]) }}">{{ $num }}</a></td>
                <td><span class="pill">{{ $typeNames[$e->type_label] ?? $e->type_name }}</span></td>
                <td>
                    {{ implode(', ', array_keys($s['from'] ?? [])) ?: '—' }} <span class="muted">→</span> {{ implode(', ', array_keys($s['to'] ?? [])) ?: '—' }}
                    @if ($e->notes)<div class="muted" style="font-size:13px">{{ \Illuminate\Support\Str::limit($e->notes, 90) }}</div>@endif
                </td>
                <td class="num">{{ Money::format($e->dr_total) }}</td>
                <td>
                    <div class="actions" style="justify-content:flex-end">
                        <a class="btn ghost sm" href="{{ route('entries.show', [$e->type_label, $e->id]) }}">View</a>
                        <a class="btn ghost sm" href="{{ route('entries.edit', [$e->type_label, $e->id]) }}">Edit</a>
                        <form class="inline" method="POST" action="{{ route('entries.destroy', [$e->type_label, $e->id]) }}" onsubmit="return confirm('Delete this entry?')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted" style="text-align:center;padding:24px">No entries found.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if ($pages > 1)
    <div class="actions" style="margin-top:12px;align-items:center">
        @if ($page > 1)<a class="btn ghost sm" href="{{ route('entries.index', $qs(['page' => $page - 1])) }}">&larr; Newer</a>@endif
        <span class="muted">Page {{ $page }} of {{ $pages }}</span>
        @if ($page < $pages)<a class="btn ghost sm" href="{{ route('entries.index', $qs(['page' => $page + 1])) }}">Older &rarr;</a>@endif
    </div>
@endif
@endsection
