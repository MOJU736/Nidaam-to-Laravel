<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Entry;
use App\Models\Tenant\EntryType;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Tag;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Journal entries made by hand: Journal, Money In, Money Out, Transfer and custom entry types. */
class EntryController extends Controller
{
    public function __construct(protected EntryService $entries, protected ChartOfAccounts $chart) {}

    public function index(Request $request)
    {
        $types = $this->entries->listTypes();
        $f = [
            'q' => trim((string) $request->query('q')), 'type' => (string) $request->query('type'),
            'from' => $this->ymd($request->query('from')), 'to' => $this->ymd($request->query('to')),
            'account' => (int) $request->query('account') ?: '', 'tag' => (int) $request->query('tag') ?: '',
            'min' => $this->amount($request->query('min')), 'max' => $this->amount($request->query('max')),
            'all' => $request->query('all') ? '1' : '',
        ];
        if (! isset($types[$f['type']])) {
            $f['type'] = '';
        }
        $page = max(1, (int) $request->query('page', 1));
        $result = $this->entries->paginate($f, $page);

        return view('entries.index', $result + [
            'filter' => $f, 'page' => $page, 'typeNames' => $types, 'entries' => $this->entries,
            'accounts' => Ledger::orderBy('name')->get(['id', 'name']), 'tags' => Tag::orderBy('title')->get(['id', 'title']),
            // "New" buttons: Journal and any custom type - Money In / Out and Transfer have their own screens
            'quickTypes' => EntryType::whereNotIn('label', ['sales_invoice', 'purchase_bill', 'receipt', 'payment', 'contra'])->orderBy('id')->get(),
        ]);
    }

    // ---- journal entry form (any hand-made type) ----------------------------

    public function create(string $label)
    {
        return view('entries.form', $this->formData($this->type($label), null, null));
    }

    public function store(Request $request, string $label)
    {
        $type = $this->type($label);
        $header = $this->validateHeader($request, $type, null);
        $lines = $this->entries->normalizeLines((array) $request->input('lines'), $type, $this->decimals());
        $this->entries->guard($header['date']);

        $entry = $this->entries->save($type, null, $header, $lines);
        $name = $this->entries->entryNumber($entry->number, $type);
        ActivityLog::add("Added {$type->name}: $name");

        $to = $request->has('save_and_new') ? route('entries.create', $label) : route('entries.show', [$label, $entry->id]);

        return redirect($to)->with('message', "{$type->name} $name was created.");
    }

    public function show(string $label, int $id)
    {
        $type = $this->type($label);
        $entry = Entry::where('entrytype_id', $type->id)->findOrFail($id);
        $parties = $this->entries->partyMap();

        $lines = DB::connection('tenant')->table('entryitems as ei')->leftJoin('ledgers as l', 'l.id', '=', 'ei.ledger_id')
            ->where('ei.entry_id', $entry->id)->orderBy('ei.id')->get(['ei.*', 'l.name as ledger_name']);
        $projects = $lines->pluck('project_id')->filter()->unique();
        $projectNames = ($projects->isEmpty() || ! \App\Support\Tables::has('projects')) ? collect() : DB::connection('tenant')->table('projects')->whereIn('id', $projects)->get(['id', 'number', 'name'])->keyBy('id');

        $rows = $lines->map(fn ($l) => [
            'dc' => $l->dc, 'amount' => $l->amount, 'narration' => $l->narration,
            'account' => isset($parties[$l->ledger_id]) ? $parties[$l->ledger_id]['account'].' - '.$parties[$l->ledger_id]['name'] : ($l->ledger_name ?? '(deleted account)'),
            'project' => ($l->project_id ?? null) && isset($projectNames[$l->project_id]) ? "#{$projectNames[$l->project_id]->number} {$projectNames[$l->project_id]->name}".(($l->cost_type ?? null) ? ' - '.(EntryService::COST_TYPES[$l->cost_type] ?? $l->cost_type) : '') : '',
        ])->sortByDesc('dc')->values(); // debits first, like the journal is written

        return view('entries.show', [
            'entry' => $entry, 'type' => $type, 'label' => $label, 'typeName' => $this->entries->typeName($type), 'number' => $this->entries->entryNumber($entry->number, $type),
            'lines' => $rows, 'linked' => $this->entries->linkedDocument($entry->id), 'tag' => $entry->tag_id ? Tag::find($entry->tag_id) : null,
            'locked' => $this->entries->closedPeriodError($entry->date?->toDateString()),
        ]);
    }

    public function edit(string $label, int $id)
    {
        $type = $this->type($label);
        $entry = Entry::where('entrytype_id', $type->id)->findOrFail($id);
        if ($doc = $this->entries->linkedDocument($entry->id)) {
            return redirect()->route('entries.index')->with('error', 'This entry was made by '.(preg_match('/^[aeiou]/i', $doc['label']) ? 'an ' : 'a ')."{$doc['label']}. To change it, edit, revert or void the {$doc['label']} itself.");
        }

        return view('entries.form', $this->formData($type, $entry, null));
    }

    public function update(Request $request, string $label, int $id)
    {
        $type = $this->type($label);
        $entry = Entry::where('entrytype_id', $type->id)->findOrFail($id);
        if ($doc = $this->entries->linkedDocument($entry->id)) {
            return redirect()->route('entries.index')->with('error', "This entry was made by a {$doc['label']}. Change it there.");
        }
        abort_if($this->chart->companyLocked(), 403, 'This company account is locked.');

        $header = $this->validateHeader($request, $type, $entry);
        $lines = $this->entries->normalizeLines((array) $request->input('lines'), $type, $this->decimals());
        // both the date it had and the date it is being changed to must be in an open period
        if ($err = $this->entries->closedPeriodError([$header['date'], $entry->date?->toDateString()])) {
            return back()->withInput()->withErrors(['date' => $err]);
        }

        $entry = $this->entries->save($type, $entry, $header, $lines);
        $name = $this->entries->entryNumber($entry->number, $type);
        ActivityLog::add("Updated {$type->name}: $name");

        return redirect()->route('entries.show', [$label, $entry->id])->with('message', "{$type->name} $name was updated.");
    }

    public function destroy(string $label, int $id)
    {
        $type = $this->type($label);
        $entry = Entry::where('entrytype_id', $type->id)->findOrFail($id);
        $name = $this->entries->entryNumber($entry->number, $type);
        $this->entries->delete($entry);
        ActivityLog::add("Deleted {$type->name}: $name");

        return redirect()->route('entries.index')->with('message', "{$type->name} $name was deleted.");
    }

    // ---- quick screens -------------------------------------------------------

    public function transferForm()
    {
        return view('entries.transfer', ['banks' => $this->entries->accountOptions(4)]);
    }

    public function transfer(Request $request)
    {
        $d = $request->validate([
            'from_ledger_id' => ['required', 'integer', 'min:1'], 'to_ledger_id' => ['required', 'integer', 'min:1', 'different:from_ledger_id'],
            'date' => ['required', 'date'], 'amount' => $this->amountRule(), 'memo' => ['nullable', 'string', 'max:500'],
        ], ['to_ledger_id.different' => 'Choose two different accounts to transfer between.']);
        $d['amount'] = $this->cleanAmount($d['amount']);

        $entry = $this->entries->transfer((int) $d['from_ledger_id'], (int) $d['to_ledger_id'], $d['date'], $d['amount'], $d['memo'] ?? null);
        $name = $this->entries->entryNumber($entry->number, $this->entries->typeByLabel('contra'));

        return redirect($request->has('save_and_new') ? route('entries.transfer') : route('entries.index'))->with('message', "Transfer $name was recorded.");
    }

    public function simpleForm(string $direction)
    {
        abort_unless(in_array($direction, ['in', 'out'], true), 404);

        return view('entries.simple', [
            'direction' => $direction, 'banks' => $this->entries->accountOptions(4), 'categories' => $this->entries->accountOptions(5),
            'projects' => $direction === 'out' ? $this->entries->projectOptions() : null,
        ]);
    }

    public function simple(Request $request, string $direction)
    {
        abort_unless(in_array($direction, ['in', 'out'], true), 404);
        $d = $request->validate([
            'bank_ledger_id' => ['required', 'integer', 'min:1'], 'category_ledger_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date'], 'amount' => $this->amountRule(), 'memo' => ['nullable', 'string', 'max:500'], 'project_tag' => ['nullable', 'string', 'max:60'],
        ]);

        $entry = $this->entries->simple($direction, (int) $d['bank_ledger_id'], (int) $d['category_ledger_id'], $d['date'], $this->cleanAmount($d['amount']), $d['memo'] ?? null, $d['project_tag'] ?? null);
        $name = $this->entries->entryNumber($entry->number, $this->entries->typeByLabel($direction === 'in' ? 'receipt' : 'payment'));

        return redirect($request->has('save_and_new') ? route('entries.simple', $direction) : route('entries.index'))
            ->with('message', ($direction === 'in' ? 'Money In' : 'Money Out')." $name was recorded.");
    }

    // ---- helpers ---------------------------------------------------------------

    protected function type(string $label): EntryType
    {
        return EntryType::where('label', $label)->firstOrFail();
    }

    protected function decimals(): int
    {
        return (int) (Setting::first()->decimal_places ?? 2);
    }

    protected function amountRule(): array
    {
        $places = $this->decimals();
        $pattern = $places > 0 ? '/^\d+(\.\d{1,'.$places.'})?$/' : '/^\d+$/';

        return ['required', 'string', function ($attr, $value, $fail) use ($pattern, $places) {
            $v = $this->cleanAmount($value);
            if (! is_numeric($v) || (float) $v <= 0) {
                return $fail('Enter an amount greater than zero.');
            }
            if (! preg_match($pattern, $v)) {
                $fail("The amount can have at most $places decimal place".($places === 1 ? '' : 's').'.');
            }
        }];
    }

    protected function cleanAmount($v): string
    {
        return str_replace([',', ' '], '', trim((string) $v));
    }

    protected function ymd($v): string
    {
        $d = \DateTime::createFromFormat('Y-m-d', (string) $v);

        return $d && $d->format('Y-m-d') === $v ? $v : '';
    }

    protected function amount($v): string
    {
        $v = $this->cleanAmount($v);

        return $v !== '' && is_numeric($v) && (float) $v >= 0 ? $v : '';
    }

    protected function validateHeader(Request $request, EntryType $type, ?Entry $entry): array
    {
        $d = $request->validate([
            'date' => ['required', 'date'], 'tag_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:500'], 'number' => ['nullable', 'string', 'max:18'],
        ]);

        return $d;
    }

    protected function formData(EntryType $type, ?Entry $entry, $unused): array
    {
        $old = old('lines');
        $parties = $this->entries->partyMap();

        if (is_array($old)) {
            // a posted row may omit fields; fill them so the form can show it again
            $blank = ['account' => '', 'party' => '', 'debit' => '', 'credit' => '', 'narration' => '', 'project' => ''];
            $lines = array_map(fn ($l) => array_merge($blank, array_map(fn ($v) => (string) $v, (array) $l)), array_values($old));
        } elseif ($entry) {
            $lines = DB::connection('tenant')->table('entryitems')->where('entry_id', $entry->id)->orderBy('id')->get()->map(function ($d) use ($parties) {
                $p = $parties[$d->ledger_id] ?? null;

                return ['account' => $p ? 'party:'.$p['side'] : (string) $d->ledger_id, 'party' => $p ? (string) $d->ledger_id : '',
                    'debit' => $d->dc === 'D' ? $d->amount : '', 'credit' => $d->dc === 'C' ? $d->amount : '',
                    'narration' => $d->narration, 'project' => ($d->project_id ?? null) ? $d->project_id.':'.$d->cost_type : ''];
            })->all();
        } else {
            $lines = [];
        }
        while (count($lines) < 2) {
            $lines[] = ['account' => '', 'party' => '', 'debit' => '', 'credit' => '', 'narration' => '', 'project' => ''];
        }

        $accounts = $this->entries->accountOptions((int) $type->restriction_bankcash);
        // an account already on a line stays in the list even if it would not be offered for a new line
        $listed = array_flip(array_column($accounts, 'v'));
        foreach ($lines as $l) {
            if ($l['account'] !== '' && ctype_digit((string) $l['account']) && ! isset($listed[$l['account']])) {
                $accounts[] = ['v' => (string) $l['account'], 't' => Ledger::find($l['account'])?->name ?? '(deleted account)', 'd' => 0, 'g' => false];
            }
        }
        $keep = collect($lines)->pluck('project')->filter()->map(fn ($p) => (int) $p)->all();

        return [
            'type' => $type, 'typeName' => $this->entries->typeName($type), 'entry' => $entry, 'lines' => $lines, 'accounts' => $accounts,
            'parties' => $this->entries->partyChoices(), 'normal' => $this->entries->normalSides(), 'tags' => Tag::orderBy('title')->get(['id', 'title']),
            'projects' => $this->entries->projectOptions($keep), 'nextNumber' => $entry ? null : $this->entries->nextNumber($type->id),
            'numberLabel' => $entry ? $this->entries->entryNumber($entry->number, $type) : null,
        ];
    }
}
