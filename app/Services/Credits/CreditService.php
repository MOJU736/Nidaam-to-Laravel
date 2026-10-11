<?php

namespace App\Services\Credits;

use App\Models\Tenant\Ledger;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Services\Inventory\ItemService;
use App\Services\Purchases\PurchasesService;
use App\Services\Sales\Allocations;
use App\Services\Sales\SalesService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Credit notes, returns and refunds for both sides. Ported from the legacy Credits_model.
 *
 *   'ar'  Customer credit notes (CN-0001): money given back to a customer - a return, a price correction, goodwill.
 *   'ap'  Supplier credits (VC-0001): money a supplier gives back - goods returned to them, an overcharge.
 *
 * A credit note can be applied to invoices / bills (as a "credit" source in the allocation engine) or paid out as a cash
 * refund; an overpayment left on a payment can be refunded the same way. Each posts one balanced journal entry:
 *   Customer credit note: Dr each line's income account, Dr sales tax, Cr the customer; per restocked stock line also
 *                         Dr Inventory Asset / Cr Cost of Goods Sold at cost.
 *   Supplier credit:      Dr the vendor, Cr each line's account (Inventory Asset for stock items), Cr purchase tax.
 *   Refund to a customer: Dr the customer, Cr bank/cash.      Refund from a supplier: Dr bank/cash, Cr the vendor.
 *   Bad-debt write-off:   Dr Bad Debts, Cr the customer (a credit note of kind 'writeoff', numbered WO-0001).
 */
class CreditService
{
    public function __construct(
        protected Allocations $allocs,
        protected ChartOfAccounts $chart,
        protected EntryService $entries,
        protected ItemService $items,
        protected SalesService $sales,
        protected PurchasesService $purchases,
    ) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    /** Everything that differs between the two sides. */
    public function cfg(string $side): array
    {
        return match ($side) {
            'ar' => ['side' => 'ar', 'party_table' => 'customers', 'party_word' => 'customer', 'party_col' => 'customer_id', 'doc_table' => 'invoices', 'line_table' => 'invoice_items', 'line_fk' => 'invoice_id',
                'prefix' => 'CN-', 'name' => 'Credit Note', 'names' => 'Credit Notes', 'doc_word' => 'invoice', 'pay_table' => 'payments', 'route' => 'credit-notes', 'doc_route' => 'invoices.show',
                'party_route' => 'customers.show', 'pay_route' => 'payments.show'],
            'ap' => ['side' => 'ap', 'party_table' => 'vendors', 'party_word' => 'vendor', 'party_col' => 'vendor_id', 'doc_table' => 'bills', 'line_table' => 'bill_items', 'line_fk' => 'bill_id',
                'prefix' => 'VC-', 'name' => 'Supplier Credit', 'names' => 'Supplier Credits', 'doc_word' => 'bill', 'pay_table' => 'vendor_payments', 'route' => 'supplier-credits', 'doc_route' => 'bills.show',
                'party_route' => 'vendors.show', 'pay_route' => 'vendor-payments.show'],
            default => throw new \InvalidArgumentException("Unknown credit side: $side"),
        };
    }

    public function label(string $side, $number, string $kind = 'credit'): string
    {
        return ($kind === 'writeoff' ? 'WO-' : $this->cfg($side)['prefix']).str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    protected function docLabel(string $side, $number): string
    {
        return $side === 'ar' ? $this->sales->label('invoice', $number) : $this->purchases->billLabel($number);
    }

    protected function settings(string $side): object
    {
        return $side === 'ar' ? $this->sales->settings() : $this->purchases->settings();
    }

    protected function div($a, $b): string
    {
        return Money::isZero($b) ? '0.00' : Money::round(bcdiv((string) $a, (string) $b, 8));
    }

    protected function guardBooks(?string $date, string $what): void
    {
        if ($this->chart->companyLocked()) {
            $this->fail("This account is locked. Unlock it under Account Settings before $what.");
        }
        if ($date && ($err = $this->entries->closedPeriodError(substr($date, 0, 10)))) {
            $this->fail($err);
        }
    }

    protected function lockStatus(int $id): ?string
    {
        return $this->db()->selectOne('SELECT status FROM credit_notes WHERE id = ? FOR UPDATE', [$id])?->status;
    }

    // ---- reading ----------------------------------------------------------------------

    public function party(string $side, $id): ?object
    {
        return $this->db()->table($this->cfg($side)['party_table'])->where('id', $id)->first();
    }

    public function partyOptions(string $side)
    {
        return $this->db()->table($this->cfg($side)['party_table'])->where('status', 1)->orderBy('name')->get(['id', 'name']);
    }

    /** Every credit note of one side, newest first, with what is left on each. */
    public function list(string $side): array
    {
        $c = $this->cfg($side);
        $rows = $this->db()->table('credit_notes as c')->join("{$c['party_table']} as p", 'p.id', '=', 'c.party_id')->where('c.side', $side)
            ->orderByDesc('c.date')->orderByDesc('c.id')->get(['c.*', 'p.name as party_name']);
        $ids = $rows->where('status', 'issued')->pluck('id')->all();
        $applied = $this->allocs->appliedBySource($side, $ids, 'credit');
        $refunded = $this->allocs->refundedBySource($side, $ids, 'credit');

        return $rows->each(function ($r) use ($side, $applied, $refunded) {
            $r->label = $this->label($side, $r->number, $r->kind);
            $r->remaining = $r->status === 'issued' ? Money::sub(Money::sub($r->total, $applied[$r->id] ?? 0), $refunded[$r->id] ?? 0) : null;
        })->all();
    }

    /** One credit note with its lines, party and the label of the document it was made for. */
    public function get(string $side, int $id): ?object
    {
        $c = $this->cfg($side);
        $cn = $this->db()->table('credit_notes as c')->join("{$c['party_table']} as p", 'p.id', '=', 'c.party_id')->where('c.side', $side)->where('c.id', $id)
            ->first(['c.*', 'p.name as party_name', 'p.ledger_id as party_ledger_id', 'p.email as party_email', 'p.address as party_address']);
        if (! $cn) {
            return null;
        }
        $cn->label = $this->label($side, $cn->number, $cn->kind);
        $cn->items = $this->db()->table('credit_note_items as i')->leftJoin('items as it', 'it.id', '=', 'i.item_id')->where('i.credit_note_id', $id)->orderBy('i.sort_order')->orderBy('i.id')
            ->get(['i.*', 'it.name as item_name', 'it.type as item_type']);
        $cn->source_label = null;
        $cn->source_url = null;
        if ($cn->source_doc_id && ($doc = $this->db()->table($c['doc_table'])->where('id', $cn->source_doc_id)->first(['id', 'number']))) {
            $cn->source_label = $this->docLabel($side, $doc->number);
            $cn->source_url = route($c['doc_route'], $doc->id);
        }

        return $cn;
    }

    /** Credit notes and write-offs are numbered separately (CN-0001, WO-0001). */
    public function nextNumber(string $side, string $kind = 'credit'): int
    {
        return ((int) $this->db()->table('credit_notes')->where('side', $side)->where('kind', $kind)->max('number')) + 1;
    }

    /**
     * Lines copied from an issued invoice / bill at the price actually charged (line discount and the line's share of a
     * whole-document discount taken off), so crediting everything gives back what was billed. Null if it can't be credited.
     */
    public function prefill(string $side, int $docId): ?array
    {
        $c = $this->cfg($side);
        $doc = $this->db()->table($c['doc_table'])->where('id', $docId)->first();
        if (! $doc || $doc->status !== 'issued') {
            return null;
        }
        $lines = $this->db()->table($c['line_table'])->where($c['line_fk'], $docId)->orderBy('sort_order')->orderBy('id')->get();
        $itemRows = $this->db()->table('items')->whereIn('id', $lines->pluck('item_id')->filter()->all())->get()->keyBy('id');
        $out = [];
        foreach ($lines as $l) {
            $net = Money::sub($l->amount, $l->discount_amount ?? 0);
            if (Money::positive($doc->discount_amount ?? 0) && Money::positive($doc->subtotal ?? 0)) {
                $net = Money::sub($net, $this->div(Money::mul($net, $doc->discount_amount), $doc->subtotal));
            }
            $qty = (float) $l->qty > 0 ? (string) $l->qty : '1';
            $item = $l->item_id ? $itemRows->get($l->item_id) : null;
            $stock = $item && $item->type === 'inventory';
            $out[] = ['item_id' => $l->item_id, 'source_line_id' => $l->id, 'ledger_id' => $l->ledger_id ?? null, 'description' => $l->description, 'qty' => $qty,
                'unit_price' => $this->div($net, $qty), 'restock' => $stock ? 1 : 0];
        }
        $pct = $doc->tax_percent !== null && $doc->tax_percent !== '' ? $doc->tax_percent : null;

        return ['party_id' => $doc->{$c['party_col']}, 'source_doc_id' => $doc->id, 'source_label' => $this->docLabel($side, $doc->number), 'tax_percent' => $pct,
            'tax_total' => $pct === null ? $doc->tax_total : null, 'items' => $out];
    }

    // ---- saving a draft ---------------------------------------------------------------------

    /** Create or update a DRAFT credit note. Every amount is worked out here, never taken from the form. Returns its id. */
    public function save(string $side, array $d, array $rows, ?int $id = null): int
    {
        $c = $this->cfg($side);
        $party = $this->party($side, $d['party_id'] ?? 0) ?? $this->fail('Choose a '.$c['party_word'].'.');
        if ($id) {
            $existing = $this->db()->table('credit_notes')->where('id', $id)->where('side', $side)->first() ?? $this->fail($c['name'].' not found.');
            if ($existing->status !== 'draft' || $existing->kind === 'writeoff') {
                $this->fail('Only a draft '.strtolower($c['name']).' can be edited.');
            }
        }

        $clean = [];
        $subtotal = '0.00';
        foreach ($rows as $r) {
            $desc = trim((string) ($r['description'] ?? ''));
            $qty = str_replace(',', '', trim((string) ($r['qty'] ?? '')));
            $price = str_replace(',', '', trim((string) ($r['unit_price'] ?? '')));
            $itemId = ! empty($r['item_id']) ? (int) $r['item_id'] : null;
            if ($desc === '' && ! $itemId && ($price === '' || (float) $price == 0.0)) {
                continue; // an empty row
            }
            if (! is_numeric($qty) || (float) $qty <= 0) {
                $this->fail('Every line needs a quantity greater than zero.');
            }
            if (! is_numeric($price) || (float) $price < 0) {
                $this->fail('Every line needs a price of zero or more.');
            }
            $item = $itemId ? $this->db()->table('items')->where('id', $itemId)->first() : null;
            if ($itemId && ! $item) {
                $this->fail('One of the items no longer exists.');
            }
            $desc = $desc !== '' ? $desc : (string) ($item->name ?? '');
            if ($desc === '') {
                $this->fail('Every line needs a description.');
            }
            $stock = $item && $item->type === 'inventory';
            $ledgerId = ! empty($r['ledger_id']) ? (int) $r['ledger_id'] : null;
            if ($side === 'ap' && ! $stock && ! $ledgerId) {
                $ledgerId = $item && $item->expense_ledger_id ? (int) $item->expense_ledger_id : $this->fail("Choose an account for \"$desc\".");
            }
            $amount = Money::mul($qty, $price);
            $subtotal = Money::add($subtotal, $amount);
            $clean[] = ['item_id' => $itemId, 'source_line_id' => ! empty($r['source_line_id']) ? (int) $r['source_line_id'] : null, 'ledger_id' => $side === 'ap' ? $ledgerId : null,
                'description' => mb_substr($desc, 0, 255), 'qty' => $qty, 'unit_price' => $price, 'amount' => $amount, 'restock' => ($stock && ! empty($r['restock'])) ? 1 : 0];
        }
        if (! $clean) {
            $this->fail('Add at least one line.');
        }

        $pct = isset($d['tax_percent']) && trim((string) $d['tax_percent']) !== '' ? trim((string) $d['tax_percent']) : null;
        if ($pct !== null) {
            if (! is_numeric($pct) || (float) $pct < 0) {
                $this->fail('Tax % must be a number.');
            }
            $tax = Money::percentOf($subtotal, $pct);
        } else {
            $typed = str_replace(',', '', trim((string) ($d['tax_total'] ?? '')));
            $tax = is_numeric($typed) && (float) $typed > 0 ? Money::round($typed) : '0.00';
        }
        $total = Money::add($subtotal, $tax);
        if (! Money::positive($total)) {
            $this->fail('The total must be more than zero.');
        }

        // the document it is for must belong to the same customer / vendor
        $sourceId = null;
        if (! empty($d['source_doc_id']) && ($doc = $this->db()->table($c['doc_table'])->where('id', (int) $d['source_doc_id'])->first()) && (int) $doc->{$c['party_col']} === (int) $party->id) {
            $sourceId = (int) $doc->id;
        }

        $row = ['side' => $side, 'party_id' => (int) $party->id, 'source_doc_id' => $sourceId, 'date' => $d['date'], 'notes' => isset($d['notes']) ? mb_substr((string) $d['notes'], 0, 500) : null,
            'subtotal' => $subtotal, 'tax_percent' => $pct, 'tax_total' => $tax, 'total' => $total];

        return $this->db()->transaction(function () use ($id, $row, $clean, $side) {
            if ($id) {
                $this->db()->table('credit_notes')->where('id', $id)->update($row);
                $this->db()->table('credit_note_items')->where('credit_note_id', $id)->delete();
                $cnId = $id;
            } else {
                $cnId = $this->db()->table('credit_notes')->insertGetId($row + ['number' => $this->nextNumber($side), 'kind' => 'credit', 'status' => 'draft', 'created_at' => now()]);
            }
            foreach ($clean as $i => $line) {
                $this->db()->table('credit_note_items')->insert($line + ['credit_note_id' => $cnId, 'sort_order' => $i]);
            }

            return $cnId;
        });
    }

    // ---- issue -----------------------------------------------------------------------------

    /** Post a draft to the books. Returns ['applied' => amount applied to its own invoice / bill, 'source_label' => ...]. */
    public function issue(string $side, int $id): array
    {
        $c = $this->cfg($side);
        $cn = $this->get($side, $id) ?? $this->fail($c['name'].' not found.');
        $this->guardBooks($cn->date, 'issuing credit notes');
        if ($cn->status !== 'draft') {
            $this->fail('Only a draft can be issued.');
        }
        if ($cn->items->isEmpty()) {
            $this->fail('Add at least one line before issuing.');
        }
        $s = $this->settings($side);
        $where = $side === 'ar' ? 'Sales' : 'Purchases';
        if (! $s->entrytype_id) {
            $this->fail("$where settings are not set up yet. Open $where → Settings first.");
        }
        if (Money::positive($cn->tax_total) && ! $s->tax_ledger_id) {
            $this->fail('This '.strtolower($c['name'])." has tax but no Tax account is set under $where → Settings.");
        }
        if (! $cn->party_ledger_id) {
            $this->fail('This '.$c['party_word'].' has no account.');
        }

        $itemRows = $this->db()->table('items')->whereIn('id', $cn->items->pluck('item_id')->filter()->all())->get()->keyBy('id');
        $itemSettings = null;
        $lineLedger = [];  // credit_note_items.id => the account the line posts to
        $lineCost = [];    // credit_note_items.id => stock value moved
        $stock = [];       // item id => quantity change (+ back into stock, - out of stock)
        $costTotal = '0.00';
        foreach ($cn->items as $l) {
            $item = $l->item_id ? $itemRows->get($l->item_id) : null;
            $isStock = $item && $item->type === 'inventory';
            if ($isStock && $itemSettings === null) {
                $this->items->ensureInventoryAccounts();
                $itemSettings = $this->items->settings();
                if (! $itemSettings->inventory_asset_ledger_id || ($side === 'ar' && ! $itemSettings->cogs_ledger_id)) {
                    $this->fail('This '.strtolower($c['name']).' has a stock item, but the Inventory Asset / Cost of Goods Sold accounts are not set under Items → Settings.');
                }
            }
            if ($side === 'ar') {
                $lineLedger[$l->id] = ($item && $item->sales_ledger_id) ? $item->sales_ledger_id : ($s->sales_ledger_id ?: $this->fail("No income account for \"{$l->description}\". Set the Sales account under Sales → Settings."));
                if ($isStock && $l->restock) {
                    // back into stock at what it cost when it was sold (the invoice line's recorded cost), else at the item's current cost
                    $unit = $item->purchase_cost;
                    if ($l->source_line_id && ($src = $this->db()->table('invoice_items')->where('id', $l->source_line_id)->first(['qty', 'cogs_amount'])) && $src->cogs_amount !== null && (float) $src->qty > 0) {
                        $unit = $this->div($src->cogs_amount, $src->qty);
                    }
                    $lineCost[$l->id] = Money::mul($l->qty, $unit);
                    $costTotal = Money::add($costTotal, $lineCost[$l->id]);
                    $stock[$item->id] = Money::add($stock[$item->id] ?? 0, $l->qty);
                }
            } else {
                $lineLedger[$l->id] = $isStock ? $itemSettings->inventory_asset_ledger_id : ($l->ledger_id ?: $this->fail("Choose an account for \"{$l->description}\"."));
                if ($isStock && $l->restock) {
                    $lineCost[$l->id] = $l->amount;
                    $stock[$item->id] = Money::sub($stock[$item->id] ?? 0, $l->qty);
                }
            }
        }

        // never post an entry that doesn't balance
        $sum = $cn->items->reduce(fn ($carry, $l) => Money::add($carry, $l->amount), '0.00');
        $check = Money::add($sum, $cn->tax_total);
        if (Money::cmp($check, $cn->total) !== 0) {
            $this->fail("The totals don't add up (lines + tax $check, total {$cn->total}), so it was not posted. Open it, press Save once, then issue again.");
        }
        $entryTotal = Money::add($cn->total, $costTotal);
        $narr = "{$c['name']} {$cn->label}";

        $applied = '0.00';
        $this->db()->transaction(function () use ($side, $id, $cn, $s, $c, $lineLedger, $lineCost, $stock, $costTotal, $entryTotal, $itemSettings, $narr, &$applied) {
            if ($this->lockStatus($id) !== 'draft') {
                $this->fail('Only a draft can be issued.');
            }
            // stock can't go below zero: a return to the supplier can only send back what is on hand
            $out = [];
            foreach ($stock as $itemId => $change) {
                if (Money::cmp($change, 0) < 0) {
                    $out[$itemId] = Money::neg($change);
                }
            }
            if ($out && ($short = $this->sales->stockShortfall($out, 'It was not issued - it is still a draft.', ''))) {
                $this->fail($short);
            }

            $entryId = $this->db()->table('entries')->insertGetId(['entrytype_id' => $s->entrytype_id, 'tag_id' => null, 'number' => $this->entries->nextNumber($s->entrytype_id), 'date' => substr($cn->date, 0, 10),
                'dr_total' => $entryTotal, 'cr_total' => $entryTotal, 'notes' => "$narr - {$cn->party_name}".($cn->source_label ? " (for {$cn->source_label})" : '')]);
            $lineDc = $side === 'ar' ? 'D' : 'C';
            $lines = [];
            foreach ($cn->items as $l) {
                $lines[] = ['ledger_id' => $lineLedger[$l->id], 'amount' => $l->amount, 'dc' => $lineDc, 'narration' => "$narr - {$l->description}"];
            }
            if (Money::positive($cn->tax_total)) {
                $lines[] = ['ledger_id' => $s->tax_ledger_id, 'amount' => $cn->tax_total, 'dc' => $lineDc, 'narration' => "$narr - Tax"];
            }
            $lines[] = ['ledger_id' => $cn->party_ledger_id, 'amount' => $cn->total, 'dc' => $side === 'ar' ? 'C' : 'D', 'narration' => $narr];
            if ($side === 'ar' && Money::positive($costTotal)) {
                $lines[] = ['ledger_id' => $itemSettings->inventory_asset_ledger_id, 'amount' => $costTotal, 'dc' => 'D', 'narration' => "$narr - Returned to stock"];
                $lines[] = ['ledger_id' => $itemSettings->cogs_ledger_id, 'amount' => $costTotal, 'dc' => 'C', 'narration' => "$narr - Returned to stock"];
            }
            $this->db()->table('entryitems')->insert(array_map(fn ($l) => $l + ['entry_id' => $entryId], $lines));

            foreach ($lineLedger as $lineId => $ledgerId) {
                $this->db()->table('credit_note_items')->where('id', $lineId)->update(['ledger_id' => $ledgerId, 'cost_amount' => $lineCost[$lineId] ?? null]);
            }
            foreach ($stock as $itemId => $change) {
                $this->db()->update('UPDATE items SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?', [$change, $itemId]);
            }
            $this->db()->table('credit_notes')->where('id', $id)->update(['entry_id' => $entryId, 'status' => 'issued']);

            // made from an invoice / bill: it pays that document straight away, up to what is still owed; the rest stays as credit
            if ($cn->source_doc_id) {
                $open = collect($this->allocs->decorate($side, $this->db()->table($c['doc_table'])->where('id', $cn->source_doc_id)->get(['id', 'status', 'total', 'due_date'])))->first();
                if ($open && $open->status === 'issued' && Money::positive($open->balance_due)) {
                    $take = Money::cmp($cn->total, $open->balance_due) < 0 ? $cn->total : $open->balance_due;
                    $this->allocs->allocate($side, $id, [$cn->source_doc_id => $take], 'auto', 'credit');
                    $applied = $take;
                }
            }
        });
        ActivityLog::add("Issued $narr for {$cn->party_name}");

        return ['applied' => $applied, 'source_label' => $cn->source_label];
    }

    // ---- void / delete ---------------------------------------------------------------------

    /** Void an issued credit note: its entry is removed, stock it moved goes back, and what it paid becomes unpaid. Refused while it has refunds. */
    public function void(string $side, int $id): void
    {
        $c = $this->cfg($side);
        $cn = $this->get($side, $id) ?? $this->fail($c['name'].' not found.');
        $this->guardBooks($cn->date, 'voiding credit notes');
        if ($cn->status !== 'issued') {
            $this->fail('Only an issued '.strtolower($c['name']).' can be voided.');
        }
        if ($this->allocs->hasRefunds($side, 'credit', $id)) {
            $this->fail('This '.strtolower($c['name']).' has a refund recorded. Delete the refund first, then void it.');
        }

        $this->db()->transaction(function () use ($side, $id, $cn, $c) {
            if ($this->lockStatus($id) !== 'issued') {
                $this->fail('Only an issued '.strtolower($c['name']).' can be voided.');
            }
            // voiding a customer return takes the returned items back out - refused if they are gone
            if ($side === 'ar') {
                $needs = [];
                foreach ($cn->items as $l) {
                    if ($l->restock && $l->item_id && $l->cost_amount !== null) {
                        $needs[$l->item_id] = Money::add($needs[$l->item_id] ?? 0, $l->qty);
                    }
                }
                if ($needs && ($short = $this->sales->stockShortfall($needs, 'It was not voided.', 'The returned items were already sold again.'))) {
                    $this->fail($short);
                }
            }
            $this->allocs->releaseSource($side, $id, 'credit');
            $this->db()->table('credit_notes')->where('id', $id)->update(['entry_id' => null, 'status' => 'void']);
            if ($cn->entry_id) {
                $this->db()->table('entryitems')->where('entry_id', $cn->entry_id)->delete();
                $this->db()->table('entries')->where('id', $cn->entry_id)->delete();
            }
            // undo exactly what issuing did: a customer return added the quantity, a return to the supplier took it out
            foreach ($cn->items as $l) {
                if (! $l->restock || ! $l->item_id || $l->cost_amount === null) {
                    continue;
                }
                $this->db()->update("UPDATE items SET quantity_on_hand = quantity_on_hand ".($side === 'ar' ? '-' : '+')." ? WHERE id = ? AND type = 'inventory'", [$l->qty, $l->item_id]);
                $this->db()->table('credit_note_items')->where('id', $l->id)->update(['cost_amount' => null]);
            }
        });
        ActivityLog::add("Voided {$c['name']} {$cn->label} for {$cn->party_name}");
    }

    /** A draft or voided credit note can go; an issued one has to be voided first. */
    public function delete(string $side, int $id): void
    {
        $c = $this->cfg($side);
        $cn = $this->get($side, $id) ?? $this->fail($c['name'].' not found.');
        if ($cn->status === 'issued') {
            $this->fail('Void it first - that reverses it in the books - then it can be deleted.');
        }
        $this->db()->transaction(function () use ($side, $id) {
            $this->allocs->releaseSource($side, $id, 'credit');
            $this->db()->table('credit_note_items')->where('credit_note_id', $id)->delete();
            $this->db()->table('credit_notes')->where('id', $id)->where('side', $side)->delete();
        });
        ActivityLog::add("Deleted {$c['name']} {$cn->label}");
    }

    // ---- cash refunds ----------------------------------------------------------------------

    /** Cash going back, out of what is left on a credit note ($type 'credit') or on a payment that was more than owed ($type 'pay'). */
    public function refund(string $side, string $type, int $srcId, array $d): void
    {
        $c = $this->cfg($side);
        $this->guardBooks($d['date'] ?? null, 'recording refunds');
        $available = $this->allocs->available($side, $type, $srcId);
        $amount = str_replace(',', '', trim((string) ($d['amount'] ?? '')));
        if ($amount === '' || ! is_numeric($amount) || ! Money::positive($amount)) {
            $this->fail('Enter a refund amount greater than zero.');
        }
        $amount = Money::round($amount);
        if (Money::cmp($amount, $available) > 0) {
            $this->fail('Only '.Money::format($available).' is left that can be refunded.');
        }
        $bank = Ledger::find($d['ledger_id'] ?? 0);
        if (! $bank || (int) $bank->type !== 1) {
            $this->fail('Choose the bank or cash account the refund '.($side === 'ar' ? 'is paid from' : 'went into').'.');
        }
        if (empty($d['date'])) {
            $this->fail('Enter the refund date.');
        }

        if ($type === 'credit') {
            $src = $this->db()->table('credit_notes')->where('id', $srcId)->where('side', $side)->first() ?? $this->fail('That credit note no longer exists.');
            if ($src->kind === 'writeoff') {
                $this->fail("A bad-debt write-off can't be refunded.");
            }
            $partyId = $src->party_id;
            $srcLabel = $this->label($side, $src->number);
        } else {
            $src = $this->db()->table($c['pay_table'])->where('id', $srcId)->first() ?? $this->fail('That payment no longer exists.');
            $partyId = $src->{$c['party_col']};
            $srcLabel = Allocations::docLabel('', $src->number);
        }
        $party = $this->party($side, $partyId);
        if (! $party || ! $party->ledger_id) {
            $this->fail('The '.$c['party_word'].' has no account.');
        }
        // money out uses the payment entry type, money in the receipt type
        $entryType = $this->entries->typeByLabel($side === 'ar' ? 'payment' : 'receipt');
        $narr = ($side === 'ar' ? 'Refund to ' : 'Refund from ')."{$party->name} ($srcLabel)";

        $this->db()->transaction(function () use ($side, $type, $srcId, $d, $amount, $bank, $party, $partyId, $entryType, $narr) {
            $entryId = $this->db()->table('entries')->insertGetId(['entrytype_id' => $entryType->id, 'tag_id' => null, 'number' => $this->entries->nextNumber($entryType->id), 'date' => $d['date'],
                'dr_total' => $amount, 'cr_total' => $amount, 'notes' => $narr]);
            $this->db()->table('entryitems')->insert([
                ['entry_id' => $entryId, 'ledger_id' => $side === 'ar' ? $party->ledger_id : $bank->id, 'amount' => $amount, 'dc' => 'D', 'narration' => $narr],
                ['entry_id' => $entryId, 'ledger_id' => $side === 'ar' ? $bank->id : $party->ledger_id, 'amount' => $amount, 'dc' => 'C', 'narration' => $narr],
            ]);
            $this->db()->table('credit_refunds')->insert(['side' => $side, 'src_type' => $type, 'src_id' => $srcId, 'party_id' => $partyId, 'ledger_id' => $bank->id, 'date' => $d['date'], 'amount' => $amount,
                'method' => ($d['method'] ?? '') !== '' ? $d['method'] : null, 'reference' => ($d['reference'] ?? '') !== '' ? $d['reference'] : null, 'entry_id' => $entryId, 'created_at' => now()]);
            // re-check inside the transaction so two refunds sent at once can't both go through
            if (Money::cmp($this->allocs->available($side, $type, $srcId), 0) < 0) {
                $this->fail('That is more than is left to refund. Nothing was saved.');
            }
        });
        ActivityLog::add("Recorded $narr - $amount");
    }

    /** Remove a refund and its journal entry; the money is available again. Returns the refund row. */
    public function deleteRefund(string $side, int $refundId): object
    {
        $r = $this->db()->table('credit_refunds')->where('id', $refundId)->where('side', $side)->first() ?? $this->fail('That refund was already removed.');
        $this->guardBooks($r->date, 'deleting refunds');
        $this->db()->transaction(function () use ($refundId, $r) {
            $this->db()->table('credit_refunds')->where('id', $refundId)->delete();
            if ($r->entry_id) {
                $this->db()->table('entryitems')->where('entry_id', $r->entry_id)->delete();
                $this->db()->table('entries')->where('id', $r->entry_id)->delete();
            }
        });
        ActivityLog::add("Deleted a refund of {$r->amount}");

        return $r;
    }

    /** Refunds of one source, with the bank account's name. */
    public function refundsFor(string $side, string $type, int $srcId)
    {
        return $this->db()->table('credit_refunds as r')->leftJoin('ledgers as l', 'l.id', '=', 'r.ledger_id')->where('r.side', $side)->where('r.src_type', $type)->where('r.src_id', $srcId)
            ->orderBy('r.date')->orderBy('r.id')->get(['r.*', 'l.name as ledger_name']);
    }

    // ---- bad-debt write-off (customers) ---------------------------------------------------

    /** The account bad debts are charged to: the saved one, else an existing "Bad debt" account, else a new "Bad Debts" expense account. */
    public function badDebtLedger(): ?int
    {
        $s = $this->sales->settings();
        if ($s->bad_debt_ledger_id && Ledger::where('id', $s->bad_debt_ledger_id)->exists()) {
            return (int) $s->bad_debt_ledger_id;
        }
        $l = Ledger::where('code', '04-01-03-0040')->where('name', 'like', '%bad debt%')->first() ?? Ledger::where('name', 'like', '%bad debt%')->first();
        if (! $l) {
            // next to an existing expense account: the expense side of every chart is coded 04-...
            $sibling = Ledger::where('code', 'like', '04-%')->where('op_balance_dc', 'D')->where('type', 0)->orderBy('code')->first();
            if (! $sibling) {
                return null;
            }
            $l = Ledger::create(['group_id' => $sibling->group_id, 'name' => 'Bad Debts', 'code' => $this->chart->nextLedgerCode($sibling->group_id), 'op_balance' => '0.00', 'op_balance_dc' => 'D',
                'type' => 0, 'reconciliation' => 0, 'notes' => 'Customer invoices written off as not collectable (created automatically)']);
            ActivityLog::add('Created ledger "Bad Debts" for invoice write-offs');
        }
        $this->sales->saveSettings(['bad_debt_ledger_id' => $l->id]);

        return (int) $l->id;
    }

    /**
     * Write off what a customer will never pay on one invoice: Dr Bad Debts / Cr the customer, and the invoice is marked paid by it.
     * It is a credit note of kind 'writeoff' (WO-0001), so it shows on the invoice, the statement and the aging; voiding it undoes it.
     */
    public function writeOff(int $invoiceId, $amount, string $date, ?string $reason): int
    {
        $this->guardBooks($date, 'writing off invoices');
        $inv = $this->db()->table('invoices as i')->join('customers as c', 'c.id', '=', 'i.customer_id')->where('i.id', $invoiceId)
            ->first(['i.*', 'c.name as customer_name', 'c.ledger_id as customer_ledger_id']);
        if (! $inv || $inv->status !== 'issued') {
            $this->fail('Only an issued invoice can be written off.');
        }
        $due = collect($this->allocs->decorate('ar', [$inv]))->first()->balance_due;
        $amount = str_replace(',', '', trim((string) $amount));
        if ($amount === '' || ! is_numeric($amount) || ! Money::positive($amount)) {
            $this->fail('Enter the amount to write off.');
        }
        $amount = Money::round($amount);
        if (Money::cmp($amount, $due) > 0) {
            $this->fail('Only '.Money::format($due).' is still owed on this invoice.');
        }
        if (! $inv->customer_ledger_id) {
            $this->fail('This customer has no account.');
        }
        $s = $this->sales->settings();
        if (! $s->entrytype_id) {
            $this->fail('Sales settings are not set up yet. Open Sales → Settings first.');
        }
        $ledgerId = $this->badDebtLedger() ?? $this->fail('No Bad Debts account could be found or created. Pick one under Sales → Settings first.');
        $invLabel = $this->sales->label('invoice', $inv->number);
        $reason = trim((string) $reason);

        return $this->db()->transaction(function () use ($inv, $amount, $date, $reason, $s, $ledgerId, $invLabel) {
            $number = $this->nextNumber('ar', 'writeoff');
            $label = $this->label('ar', $number, 'writeoff');
            $narr = "Bad debt write-off $label - $invLabel";
            $entryId = $this->db()->table('entries')->insertGetId(['entrytype_id' => $s->entrytype_id, 'tag_id' => null, 'number' => $this->entries->nextNumber($s->entrytype_id), 'date' => $date,
                'dr_total' => $amount, 'cr_total' => $amount, 'notes' => "$narr - {$inv->customer_name}".($reason !== '' ? " ($reason)" : '')]);
            $this->db()->table('entryitems')->insert([
                ['entry_id' => $entryId, 'ledger_id' => $ledgerId, 'amount' => $amount, 'dc' => 'D', 'narration' => $narr],
                ['entry_id' => $entryId, 'ledger_id' => $inv->customer_ledger_id, 'amount' => $amount, 'dc' => 'C', 'narration' => $narr],
            ]);
            $woId = $this->db()->table('credit_notes')->insertGetId(['side' => 'ar', 'party_id' => $inv->customer_id, 'source_doc_id' => $inv->id, 'number' => $number, 'date' => $date, 'status' => 'issued',
                'kind' => 'writeoff', 'notes' => $reason !== '' ? mb_substr($reason, 0, 500) : null, 'subtotal' => $amount, 'tax_percent' => null, 'tax_total' => '0.00', 'total' => $amount,
                'entry_id' => $entryId, 'created_at' => now()]);
            $this->db()->table('credit_note_items')->insert(['credit_note_id' => $woId, 'ledger_id' => $ledgerId, 'description' => "Bad debt written off - $invLabel", 'qty' => '1', 'unit_price' => $amount,
                'amount' => $amount, 'restock' => 0, 'sort_order' => 0]);
            $this->allocs->allocate('ar', $woId, [$inv->id => $amount], 'writeoff', 'credit');
            ActivityLog::add("Wrote off $amount on Sales Invoice #{$inv->number} for {$inv->customer_name} ($label)");

            return $woId;
        });
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['credit' => $message]);
    }
}
