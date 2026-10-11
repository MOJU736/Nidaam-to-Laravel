@php use App\Support\Money; $hasDisc = $doc->items->contains(fn ($i) => Money::positive($i->discount_amount ?? 0)); @endphp
<div class="card tbl">
    <table>
        <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th>@if ($hasDisc)<th class="num">Discount</th>@endif<th class="num">Amount</th></tr></thead>
        <tbody>
        @foreach ($doc->items as $i)
            <tr><td>{{ $i->description }}</td><td class="num">{{ rtrim(rtrim((string) $i->qty, '0'), '.') }}</td><td class="num">{{ Money::format($i->unit_price) }}</td>
                @if ($hasDisc)<td class="num">{{ Money::positive($i->discount_amount ?? 0) ? '-'.Money::format($i->discount_amount) : '' }}</td>@endif
                <td class="num">{{ Money::format(Money::sub($i->amount, $i->discount_amount ?? 0)) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot>
            @php $cols = $hasDisc ? 4 : 3; @endphp
            <tr><td colspan="{{ $cols }}" class="num">Subtotal</td><td class="num">{{ Money::format($doc->subtotal) }}</td></tr>
            @if (Money::positive($doc->discount_amount ?? 0))<tr><td colspan="{{ $cols }}" class="num">Discount</td><td class="num">-{{ Money::format($doc->discount_amount) }}</td></tr>@endif
            @if (Money::positive($doc->tax_total))<tr><td colspan="{{ $cols }}" class="num">Tax{{ $doc->tax_percent !== null ? ' ('.rtrim(rtrim((string) $doc->tax_percent, '0'), '.').'%)' : '' }}</td><td class="num">{{ Money::format($doc->tax_total) }}</td></tr>@endif
            <tr><td colspan="{{ $cols }}" class="num"><strong>Total</strong></td><td class="num"><strong>{{ Money::format($doc->total) }}</strong></td></tr>
        </tfoot>
    </table>
</div>
