<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Item;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Services\Credits\CreditService;
use App\Services\Sales\Allocations;
use App\Services\Sales\SalesService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer credit notes and supplier credits (one controller, the route's `side` says which), their refunds,
 * and bad-debt write-offs. Rules live in CreditService.
 */
class CreditNoteController extends Controller
{
    public function __construct(protected CreditService $credits, protected Allocations $allocs, protected EntryService $entries, protected ChartOfAccounts $chart, protected SalesService $sales) {}

    /** Route names and words for one side. */
    protected function ui(string $side): array
    {
        abort_unless(in_array($side, ['ar', 'ap'], true), 404);

        return $this->credits->cfg($side);
    }

    protected function methods(): array
    {
        return DB::connection('tenant')->table('payment_methods')->orderBy('name')->pluck('name')->all();
    }

    public function index(string $side)
    {
        return view('credits.index', ['ui' => $this->ui($side), 'rows' => $this->credits->list($side)]);
    }

    /** New credit: ?invoice= / ?bill= copies that document's lines, ?party= preselects the customer / vendor. */
    public function create(Request $request, string $side)
    {
        $ui = $this->ui($side);
        $docId = (int) $request->query($side === 'ar' ? 'invoice' : 'bill');
        $prefill = null;
        if ($docId) {
            $prefill = $this->credits->prefill($side, $docId);
            if (! $prefill) {
                return redirect()->route($ui['doc_route'], $docId)->with('error', 'Only an issued '.$ui['doc_word'].' can be credited.');
            }
        }

        return $this->form($side, null, $prefill, ($request->query('party') ? (int) $request->query('party') : null) ?? ($prefill['party_id'] ?? null));
    }

    public function edit(int $id, string $side)
    {
        $ui = $this->ui($side);
        $cn = $this->credits->get($side, $id);
        abort_unless($cn, 404);
        if ($cn->status !== 'draft' || $cn->kind === 'writeoff') {
            return redirect()->route($ui['route'].'.show', $id)->with('error', 'Only a draft can be edited. Void it and make a new one to change it.');
        }

        return $this->form($side, $cn, null, $cn->party_id);
    }

    protected function form(string $side, ?object $cn, ?array $prefill, $partyId)
    {
        $ui = $this->ui($side);
        $oldLines = old('lines');
        if (is_array($oldLines)) {
            $lines = array_values($oldLines);
        } elseif ($cn) {
            $lines = $cn->items->map(fn ($l) => ['item_id' => $l->item_id, 'source_line_id' => $l->source_line_id, 'ledger_id' => $l->ledger_id, 'description' => $l->description,
                'qty' => rtrim(rtrim((string) $l->qty, '0'), '.'), 'unit_price' => $l->unit_price, 'restock' => (int) $l->restock])->all();
        } else {
            $lines = $prefill['items'] ?? [];
        }
        $source = $cn && $cn->source_doc_id ? ['id' => $cn->source_doc_id, 'label' => $cn->source_label] : ($prefill ? ['id' => $prefill['source_doc_id'], 'label' => $prefill['source_label']] : null);

        return view('credits.form', [
            'ui' => $ui, 'cn' => $cn, 'lines' => $lines, 'source' => $source, 'parties' => $this->credits->partyOptions($side), 'selectedParty' => old('party_id', $partyId),
            'catalog' => Item::where('status', 1)->orderBy('name')->get()->mapWithKeys(fn ($i) => [$i->id => ['name' => $i->name, 'description' => $i->description, 'type' => $i->type,
                'price' => $side === 'ar' ? (string) $i->sales_price : (string) $i->purchase_cost, 'ledger_id' => $i->expense_ledger_id]]),
            'accounts' => $side === 'ap' ? $this->accountChoices() : [],
            'taxPercent' => old('tax_percent', $cn ? ($cn->tax_percent !== null ? rtrim(rtrim((string) $cn->tax_percent, '0'), '.') : '') : ($prefill && $prefill['tax_percent'] !== null ? rtrim(rtrim((string) $prefill['tax_percent'], '0'), '.') : '')),
            'taxTotal' => old('tax_total', $cn ? ($cn->tax_percent === null && Money::positive($cn->tax_total) ? $cn->tax_total : '') : ($prefill && $prefill['tax_total'] !== null && Money::positive($prefill['tax_total']) ? $prefill['tax_total'] : '')),
            'date' => old('date', $cn ? substr($cn->date, 0, 10) : now()->format('Y-m-d')), 'notes' => old('notes', $cn->notes ?? ($prefill ? 'Credit for '.$prefill['source_label'] : '')),
        ]);
    }

    /** Expense and asset accounts a supplier credit line may credit: id => "code - name". */
    protected function accountChoices(): array
    {
        $ids = [];
        foreach (['asset', 'expense'] as $root) {
            if ($g = $this->chart->topLevelGroupId($root)) {
                $ids = array_merge($ids, $this->chart->descendantLedgerIds($g));
            }
        }
        $party = array_merge(DB::connection('tenant')->table('customers')->pluck('ledger_id')->all(), DB::connection('tenant')->table('vendors')->pluck('ledger_id')->all());

        return \App\Models\Tenant\Ledger::whereIn('id', $ids)->where(fn ($q) => $q->where('type', '!=', 1)->orWhereNull('type'))->whereNotIn('id', $party)->orderBy('code')
            ->get(['id', 'name', 'code'])->mapWithKeys(fn ($l) => [$l->id => trim(($l->code ? $l->code.' - ' : '').$l->name)])->all();
    }

    /** Save the form; "Save & issue" posts it at once (and if issuing fails it stays a draft). */
    protected function persist(Request $request, string $side, ?int $id)
    {
        $ui = $this->ui($side);
        $d = $request->validate(['party_id' => ['required', 'integer'], 'date' => ['required', 'date'], 'source_doc_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:500'],
            'tax_percent' => ['nullable', 'string', 'max:10'], 'tax_total' => ['nullable', 'string', 'max:20'], 'lines' => ['nullable', 'array']]);
        $saved = $this->credits->save($side, $d, (array) ($d['lines'] ?? []), $id);

        if ($request->input('then') === 'issue') {
            try {
                $r = $this->credits->issue($side, $saved);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return redirect()->route($ui['route'].'.show', $saved)->with('error', 'Saved as a draft, but not issued: '.collect($e->errors())->flatten()->first());
            }

            return redirect()->route($ui['route'].'.show', $saved)->with('message', $this->issuedMessage($ui, $r));
        }

        return redirect()->route($ui['route'].'.show', $saved)->with('message', $ui['name'].' saved as a draft.');
    }

    protected function issuedMessage(array $ui, array $r): string
    {
        $m = $ui['name'].' issued and posted to the books.';
        if ($r['source_label'] && Money::positive($r['applied'])) {
            $m .= ' '.Money::format($r['applied']).' was applied to '.$r['source_label'].'.';
        }

        return $m;
    }

    public function store(Request $request, string $side)
    {
        return $this->persist($request, $side, null);
    }

    public function update(Request $request, int $id, string $side)
    {
        return $this->persist($request, $side, $id);
    }

    public function show(int $id, string $side)
    {
        $ui = $this->ui($side);
        $cn = $this->credits->get($side, $id);
        abort_unless($cn, 404);
        $remaining = $cn->status === 'issued' ? $this->allocs->available($side, 'credit', $id) : '0.00';
        $has = Money::positive($remaining) && $cn->kind !== 'writeoff';

        return view('credits.show', [
            'ui' => $ui, 'cn' => $cn, 'apps' => $this->allocs->forSource($side, $id, 'credit'), 'refunds' => $this->credits->refundsFor($side, 'credit', $id), 'remaining' => $remaining,
            'openDocs' => $has ? $this->allocs->openDocs($side, $cn->party_id) : [], 'banks' => $has ? $this->entries->accountOptions(4) : [], 'methods' => $this->methods(), 'canRefund' => $has,
        ]);
    }

    public function issue(int $id, string $side)
    {
        $ui = $this->ui($side);
        $r = $this->credits->issue($side, $id);

        return redirect()->route($ui['route'].'.show', $id)->with('message', $this->issuedMessage($ui, $r));
    }

    public function void(int $id, string $side)
    {
        $ui = $this->ui($side);
        $this->credits->void($side, $id);

        return redirect()->route($ui['route'].'.show', $id)->with('message', $ui['name'].' voided and its journal entry reversed.');
    }

    public function destroy(int $id, string $side)
    {
        $ui = $this->ui($side);
        $this->credits->delete($side, $id);

        return redirect()->route($ui['route'].'.index')->with('message', $ui['name'].' deleted.');
    }

    /** "Apply" on the page: put what is left of the credit against open invoices / bills. */
    public function apply(Request $request, int $id, string $side)
    {
        $ui = $this->ui($side);
        abort_if($this->chart->companyLocked(), 403, 'This company account is locked.');
        $this->allocs->allocate($side, $id, (array) $request->input('apply'), 'manual', 'credit');
        ActivityLog::add("Applied {$ui['name']} #$id");

        return redirect()->route($ui['route'].'.show', $id)->with('message', $ui['name'].' applied.');
    }

    public function removeAllocation(int $allocationId, string $side)
    {
        $ui = $this->ui($side);
        abort_if($this->chart->companyLocked(), 403, 'This company account is locked.');
        $row = $this->allocs->remove($side, $allocationId);

        return redirect()->route($ui['route'].'.show', $row->payment_id)->with('message', 'Removed. That amount is available to apply or refund again.');
    }

    /** Cash refund out of a credit note. */
    public function refund(Request $request, int $id, string $side)
    {
        $ui = $this->ui($side);
        $this->credits->refund($side, 'credit', $id, $this->refundInput($request));

        return redirect()->route($ui['route'].'.show', $id)->with('message', 'Refund recorded and posted to the books.');
    }

    /** Cash refund of an overpayment, from a payment's own page. */
    public function refundPayment(Request $request, int $id, string $side)
    {
        $ui = $this->ui($side);
        $this->credits->refund($side, 'pay', $id, $this->refundInput($request));

        return redirect()->route($ui['pay_route'], $id)->with('message', 'Refund recorded and posted to the books.');
    }

    protected function refundInput(Request $request): array
    {
        $d = $request->validate(['refund_amount' => ['required', 'string'], 'refund_ledger_id' => ['required', 'integer'], 'refund_date' => ['required', 'date'],
            'refund_method' => ['nullable', 'string', 'max:50'], 'refund_reference' => ['nullable', 'string', 'max:100']]);

        return ['amount' => $d['refund_amount'], 'ledger_id' => $d['refund_ledger_id'], 'date' => $d['refund_date'], 'method' => $d['refund_method'] ?? '', 'reference' => $d['refund_reference'] ?? ''];
    }

    public function refundDelete(int $refundId, string $side)
    {
        $ui = $this->ui($side);
        $r = $this->credits->deleteRefund($side, $refundId);
        $to = $r->src_type === 'credit' ? route($ui['route'].'.show', $r->src_id) : route($ui['pay_route'], $r->src_id);

        return redirect($to)->with('message', 'Refund deleted and its journal entry reversed.');
    }

    // ---- bad-debt write-off ---------------------------------------------------------------

    public function writeOffForm(int $id)
    {
        $inv = $this->sales->invoice($id);
        abort_unless($inv, 404);
        if ($inv->status !== 'issued') {
            return redirect()->route('invoices.show', $id)->with('error', 'Only an issued invoice can be written off.');
        }
        $due = collect($this->allocs->decorate('ar', [$inv]))->first()->balance_due;
        if (! Money::positive($due)) {
            return redirect()->route('invoices.show', $id)->with('error', 'Nothing is owed on this invoice.');
        }

        return view('credits.write-off', ['inv' => $inv, 'label' => $this->sales->label('invoice', $inv->number), 'due' => $due]);
    }

    public function writeOff(Request $request, int $id)
    {
        $d = $request->validate(['amount' => ['required', 'string'], 'date' => ['required', 'date'], 'reason' => ['nullable', 'string', 'max:500']]);
        $woId = $this->credits->writeOff($id, $d['amount'], $d['date'], $d['reason'] ?? null);

        return redirect()->route('credit-notes.show', $woId)->with('message', 'Written off. The invoice is marked paid by it; void the write-off if the customer pays after all.');
    }
}
