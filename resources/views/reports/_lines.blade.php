@php use App\Support\Money; @endphp
@foreach ($rows as $r)
    <tr class="{{ $r['type'] }}">
        <td style="padding-left:{{ 8 + max(0, $r['level'] - 1) * 20 }}px">@if ($r['url'])<a href="{{ $r['url'] }}">{{ $r['label'] }}</a>@else{{ $r['label'] }}@endif</td>
        <td class="num @if ($r['wrong']) bad @endif">@if ($r['type'] !== 'group'){{ Money::format($r['amount']) }}@endif</td>
    </tr>
@endforeach
