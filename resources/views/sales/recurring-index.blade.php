@extends('layouts.main')
@section('title', 'Recurring invoices')
@section('content')
@php use App\Support\Money; @endphp
@include('sales._tabs')
<div class="bar"><h1 style="margin:0">Recurring invoices</h1></div>
<p class="muted" style="margin-top:0">An invoice that repeats on a schedule (rent, a retainer, a monthly service). On each date a <strong>draft</strong> invoice is created for you to check and issue; nothing is posted or emailed on its own. To start one, open an invoice and click <em>Make recurring</em>.</p>
<div class="card tbl"><table>
    <thead><tr><th>Name</th><th>Customer</th><th>Schedule</th><th>Next invoice</th><th class="num">Each</th><th class="num">Created</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($rows as $r)
        <tr>
            <td><a href="{{ route('recurring.edit', $r->id) }}">{{ $r->name }}</a></td><td>{{ $r->customer_name }}</td><td>{{ $r->schedule }}@if ($r->end_date)<div class="muted" style="font-size:13px">until {{ Money::date($r->end_date) }}</div>@endif</td>
            <td>{{ $r->status === 'ended' ? '—' : Money::date($r->next_date) }}</td><td class="num">{{ Money::format($r->amount) }}</td>
            <td class="num">{{ $r->created_count }}@if ($r->last_invoice_id) <a href="{{ route('invoices.show', $r->last_invoice_id) }}" title="Latest invoice">&rarr;</a>@endif</td>
            <td><span class="pill {{ $r->status === 'active' ? '' : ($r->status === 'paused' ? 'owes' : 'overdue') }}">{{ ucfirst($r->status) }}</span></td>
            <td style="text-align:right;white-space:nowrap">
                @if ($r->status !== 'ended')
                    <form class="inline" method="POST" action="{{ route('recurring.run', $r->id) }}" onsubmit="return confirm('Create the next draft invoice now, dated today?')">@csrf<button class="ghost sm">Create now</button></form>
                    <form class="inline" method="POST" action="{{ route('recurring.toggle', $r->id) }}">@csrf<input type="hidden" name="status" value="{{ $r->status === 'active' ? 'paused' : 'active' }}"><button class="ghost sm">{{ $r->status === 'active' ? 'Pause' : 'Resume' }}</button></form>
                @endif
                <form class="inline" method="POST" action="{{ route('recurring.destroy', $r->id) }}" onsubmit="return confirm('Delete this recurring invoice? Invoices it already created are kept.')">@csrf @method('DELETE')<button class="danger sm">Delete</button></form>
            </td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted" style="text-align:center;padding:24px">None yet. Open an invoice and click <em>Make recurring</em>.</td></tr>
    @endforelse
    </tbody>
</table></div>
@endsection
