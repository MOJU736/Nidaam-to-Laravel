@extends('layouts.main')
@section('title', 'Entry types')
@section('content')
@include('settings._tabs')
<div class="bar"><h1 style="margin:0">Entry types</h1></div>
@if ($locked)<div class="flash bad">This company is locked, so entry types cannot be changed.</div>@endif
<p class="muted" style="margin-top:0;max-width:760px">Each kind of journal entry has its own number sequence. The number is written as <strong>prefix + number (padded with zeros) + suffix</strong>, so prefix "JV-", padding 4 gives JV-0007. The account rule decides which accounts an entry of that type may use. Types the app posts to itself (marked <span class="pill">system</span>) can be edited but not renamed or deleted.</p>
<div class="card" style="margin-bottom:16px">
    <h2 style="margin-top:0">Add an entry type</h2>
    <form method="POST" action="{{ route('settings.entry-types') }}">
        @csrf
        <div class="row2">
            <div><label for="n_label">Label <span class="muted">(short code, e.g. petty_cash)</span></label><input id="n_label" name="label" maxlength="50" required pattern="[a-z][a-z0-9_]{1,49}"></div>
            <div><label for="n_name">Name</label><input id="n_name" name="name" maxlength="255" required placeholder="e.g. Petty cash voucher"></div>
        </div>
        <label for="n_desc">Description</label><input id="n_desc" name="description" maxlength="255" required>
        <div class="row2">
            <div><label for="n_num">Numbering</label><select id="n_num" name="numbering">@foreach ($numbering as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
            <div><label for="n_res">Accounts allowed</label><select id="n_res" name="restriction_bankcash">@foreach ($restrictions as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
        </div>
        <div class="row2">
            <div><label for="n_pre">Prefix</label><input id="n_pre" name="prefix" maxlength="20"></div>
            <div><label for="n_suf">Suffix</label><input id="n_suf" name="suffix" maxlength="20"></div>
        </div>
        <label for="n_pad">Zero padding <span class="muted">(digits to pad the number to; 0 for none)</span></label><input id="n_pad" type="number" name="zero_padding" min="0" max="12" value="0" style="width:100px">
        <div class="actions" style="margin-top:14px"><button @disabled($locked)>Add entry type</button></div>
    </form>
</div>
@foreach ($types as $t)
    @php $sys = in_array($t->label, $system, true); @endphp
    <details class="card" style="margin-bottom:10px;padding:14px 18px" @if ($errors->any() && old('label') === $t->label) open @endif>
        <summary style="cursor:pointer"><strong>{{ $t->name }}</strong> <span class="muted">{{ $t->label }}</span> @if ($sys)<span class="pill">system</span>@endif
            <span class="muted" style="float:right">example: {{ $t->prefix }}{{ $t->zero_padding > 0 ? str_pad('7', $t->zero_padding, '0', STR_PAD_LEFT) : '7' }}{{ $t->suffix }} · {{ $t->uses }} entr{{ $t->uses == 1 ? 'y' : 'ies' }}</span></summary>
        <form method="POST" action="{{ route('settings.entry-types.update', $t->id) }}" style="margin-top:8px">
            @csrf @method('PUT')
            <div class="row2">
                <div><label>Label</label><input name="label" value="{{ $t->label }}" maxlength="50" @readonly($sys) required></div>
                <div><label>Name</label><input name="name" value="{{ $t->name }}" maxlength="255" required></div>
            </div>
            <label>Description</label><input name="description" value="{{ $t->description }}" maxlength="255" required>
            <div class="row2">
                <div><label>Numbering</label><select name="numbering">@foreach ($numbering as $k => $l)<option value="{{ $k }}" @selected((int) $t->numbering === $k)>{{ $l }}</option>@endforeach</select></div>
                <div><label>Accounts allowed</label><select name="restriction_bankcash">@foreach ($restrictions as $k => $l)<option value="{{ $k }}" @selected((int) $t->restriction_bankcash === $k)>{{ $l }}</option>@endforeach</select></div>
            </div>
            <div class="row2">
                <div><label>Prefix</label><input name="prefix" value="{{ $t->prefix }}" maxlength="20"></div>
                <div><label>Suffix</label><input name="suffix" value="{{ $t->suffix }}" maxlength="20"></div>
            </div>
            <label>Zero padding</label><input type="number" name="zero_padding" min="0" max="12" value="{{ $t->zero_padding }}" style="width:100px">
            <div class="actions" style="margin-top:14px"><button @disabled($locked)>Save</button></div>
        </form>
        @unless ($sys || $t->uses)
            <form method="POST" action="{{ route('settings.entry-types.destroy', $t->id) }}" style="margin-top:8px" onsubmit="return confirm('Delete this entry type?')">@csrf @method('DELETE')<button class="danger sm" @disabled($locked)>Delete</button></form>
        @endunless
    </details>
@endforeach
@endsection
