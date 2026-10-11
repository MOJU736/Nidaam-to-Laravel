@extends('layouts.main')
@section('title', 'Payment methods')
@section('content')
@include('settings._tabs')
<div class="bar"><h1 style="margin:0">Payment methods</h1></div>
@if ($locked)<div class="flash bad">This company is locked, so payment methods cannot be changed.</div>@endif
<p class="muted" style="margin-top:0;max-width:720px">The list offered as "Method" when you receive a payment, pay a vendor or record an expense. A payment keeps the method name it was saved with, so renaming or deleting one here does not change old payments.</p>
<form class="card" method="POST" action="{{ route('settings.payment-methods') }}" style="margin-bottom:16px">
    @csrf
    <div class="actions" style="align-items:end"><div style="flex:1;max-width:360px"><label for="name" style="margin-top:0">New method</label><input id="name" name="name" maxlength="50" required placeholder="e.g. Mobile money"></div><button @disabled($locked)>Add</button></div>
</form>
<div class="card tbl">
    <table>
        <thead><tr><th>Name</th><th></th></tr></thead>
        <tbody>
        @forelse ($methods as $m)
            <tr>
                <td><form class="inline" method="POST" action="{{ route('settings.payment-methods.update', $m->id) }}">@csrf @method('PUT')
                    <input name="name" value="{{ $m->name }}" maxlength="50" style="width:260px;display:inline-block"> <button class="ghost sm" @disabled($locked)>Rename</button></form></td>
                <td style="text-align:right"><form class="inline" method="POST" action="{{ route('settings.payment-methods.destroy', $m->id) }}" onsubmit="return confirm('Delete this payment method?')">@csrf @method('DELETE')<button class="danger sm" @disabled($locked)>Delete</button></form></td>
            </tr>
        @empty
            <tr><td colspan="2" class="muted" style="text-align:center;padding:20px">No payment methods yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
