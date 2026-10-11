@extends('layouts.main')
@section('title', 'Tax rates')
@section('content')
@include('settings._tabs')
<div class="bar"><h1 style="margin:0">Tax rates</h1></div>
@if ($locked)<div class="flash bad">This company is locked, so tax rates cannot be changed.</div>@endif
<p class="muted" style="margin-top:0;max-width:720px">Named rates you can pick on invoices, quotations, bills, purchase orders and credit notes. Picking one fills in the tax % on the document; a document still has one tax % for the whole document. The one marked default is picked automatically on new documents.</p>
<form class="card" method="POST" action="{{ route('settings.tax-rates') }}" style="margin-bottom:16px">
    @csrf
    <div class="actions" style="align-items:end;flex-wrap:wrap">
        <div><label style="margin-top:0">Name</label><input name="name" maxlength="100" required placeholder="e.g. VAT" style="width:180px"></div>
        <div><label style="margin-top:0">Rate %</label><input name="rate" inputmode="decimal" required placeholder="5" style="width:80px"></div>
        <label style="font-weight:400;margin:0"><input type="checkbox" name="on_sales" value="1" checked> Sales</label>
        <label style="font-weight:400;margin:0"><input type="checkbox" name="on_purchases" value="1" checked> Purchases</label>
        <label style="font-weight:400;margin:0"><input type="checkbox" name="is_default" value="1"> Default</label>
        <button @disabled($locked)>Add rate</button>
    </div>
</form>
<div class="card tbl">
    <table>
        <thead><tr><th>Name</th><th class="num">Rate %</th><th>Used on</th><th>Default</th><th></th></tr></thead>
        <tbody>
        @forelse ($rates as $r)
            <tr>
                <form id="tr{{ $r->id }}" method="POST" action="{{ route('settings.tax-rates.update', $r->id) }}">@csrf @method('PUT')</form>
                <td><input form="tr{{ $r->id }}" name="name" value="{{ $r->name }}" maxlength="100" style="width:180px"></td>
                <td class="num"><input form="tr{{ $r->id }}" name="rate" value="{{ \App\Services\Settings\SettingsService::pct($r->rate) }}" inputmode="decimal" style="width:80px;text-align:right"></td>
                <td><label style="font-weight:400;margin:0"><input form="tr{{ $r->id }}" type="checkbox" name="on_sales" value="1" @checked($r->on_sales)> Sales</label>
                    <label style="font-weight:400;margin:0 0 0 8px"><input form="tr{{ $r->id }}" type="checkbox" name="on_purchases" value="1" @checked($r->on_purchases)> Purchases</label></td>
                <td><input form="tr{{ $r->id }}" type="checkbox" name="is_default" value="1" @checked($r->is_default)></td>
                <td style="text-align:right;white-space:nowrap">
                    <button form="tr{{ $r->id }}" class="ghost sm" @disabled($locked)>Save</button>
                    <form class="inline" method="POST" action="{{ route('settings.tax-rates.destroy', $r->id) }}" onsubmit="return confirm('Delete this tax rate? Documents that used it keep their tax.')">@csrf @method('DELETE')<button class="danger sm" @disabled($locked)>Delete</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted" style="text-align:center;padding:20px">No tax rates yet. Documents can still have a tax % typed in by hand.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
