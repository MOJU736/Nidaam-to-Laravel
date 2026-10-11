@extends('layouts.main')
@section('title', $title)
@section('content')
@php use App\Support\Money; @endphp
@include('reports._head', ['title' => $title, 'subtitle' => $subtitle ?? '', 'mode' => $mode, 'asof' => $asof ?? null, 'from' => $from ?? null, 'to' => $to ?? null])
@if (! empty($note))<p class="muted noprint" style="margin-top:0">{{ $note }}</p>@endif
<div class="card tbl"><table>
    <thead><tr>@foreach ($cols as $c)<th @class(['num' => $c[2] !== 'text'])>{{ $c[0] }}</th>@endforeach</tr></thead>
    <tbody>
    @forelse ($rows as $r)
        <tr>@foreach ($cols as $c)
            @php $v = $r[$c[1]] ?? ''; @endphp
            <td @class(['num' => $c[2] !== 'text'])>
                @if ($c[2] === 'money'){{ Money::format($v) }}@elseif ($c[2] === 'qty'){{ rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.') }}@elseif ($c[3] && ! empty($r['id']))<a href="{{ route($c[3], $r['id']) }}">{{ $v }}</a>@else{{ $v }}@endif
            </td>
        @endforeach</tr>
    @empty
        <tr><td colspan="{{ count($cols) }}" class="muted" style="text-align:center;padding:24px">Nothing to show.</td></tr>
    @endforelse
    @if ($totals)
        <tr>@foreach ($cols as $i => $c)
            <td @class(['num' => $c[2] !== 'text'])><strong>@if ($i === 0)Total @elseif (isset($totals[$c[1]]))@if ($c[2] === 'money'){{ Money::format($totals[$c[1]]) }}@elseif ($c[2] === 'qty'){{ rtrim(rtrim(number_format((float) $totals[$c[1]], 3, '.', ','), '0'), '.') }}@else{{ $totals[$c[1]] }}@endif @endif</strong></td>
        @endforeach</tr>
    @endif
    </tbody>
</table></div>
@endsection
