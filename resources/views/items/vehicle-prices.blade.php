@extends('layouts.main')
@section('title', 'Prices - '.$model->name)
@section('content')
@php use App\Support\Money; @endphp
@include('items._tabs')
<div class="bar"><h1 style="margin:0">{{ $model->name }} — item prices</h1><a class="muted" href="{{ route('items.vehicles') }}">&larr; Vehicle models</a></div>
<p class="muted" style="margin-top:0">Leave a price blank to use the item's normal sales price.</p>
<form method="POST" action="{{ route('items.vehicles.prices', $model->id) }}" class="card tbl">
    @csrf @method('PUT')
    <table>
        <thead><tr><th>Item</th><th class="num">Normal price</th><th class="num" style="width:180px">Price for {{ $model->name }}</th></tr></thead>
        <tbody>
        @foreach ($items as $i)
            <tr><td>{{ $i->name }}@unless ($i->status) <span class="pill">Inactive</span>@endunless</td><td class="num">{{ Money::format($i->sales_price) }}</td>
                <td><input name="price[{{ $i->id }}]" class="num" inputmode="decimal" value="{{ isset($prices[$i->id]) ? $prices[$i->id] : '' }}" placeholder="—"></td></tr>
        @endforeach
        </tbody>
    </table>
    <div class="actions" style="margin-top:16px"><button>Save prices</button></div>
</form>
@endsection
