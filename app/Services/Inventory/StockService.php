<?php

namespace App\Services\Inventory;

use App\Models\Tenant\Entry;
use App\Models\Tenant\Item;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock: batches (number + expiry date) for items that track them, and stock adjustments
 * (a count correction, damage, loss ...) that move quantity on hand and post the value to the books.
 * Ported from the legacy Batches_model and Stock_adjustments_model.
 */
class StockService
{
    const REASONS = ['count' => 'Stock count', 'damaged' => 'Damaged / expired', 'lost' => 'Lost or stolen', 'found' => 'Found / extra stock', 'own_use' => 'Used in the business', 'other' => 'Other'];

    public function __construct(protected ItemService $items, protected ChartOfAccounts $chart, protected EntryService $entries) {}

    // ---- exact quantity maths (quantities carry up to 3 decimals) -----------------

    public static function q(string|int|float|null $v): string
    {
        return number_format((float) $v, 3, '.', '');
    }

    public static function qadd($a, $b): string
    {
        return bcadd(self::q($a), self::q($b), 3);
    }

    public static function qsub($a, $b): string
    {
        return bcsub(self::q($a), self::q($b), 3);
    }

    public static function qcmp($a, $b): int
    {
        return bccomp(self::q($a), self::q($b), 3);
    }

    /** 12.500 -> "12.5", 3.000 -> "3" */
    public static function text($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.') ?: '0';
    }

    // ---- batches -----------------------------------------------------------------

    public function batchesAvailable(): bool
    {
        return Tables::has('item_batches') && Tables::has('batch_moves') && Tables::has('items', 'track_batches');
    }

    /** Batches of an item with stock, earliest expiry first (no expiry last). */
    public function batches(int $itemId)
    {
        return DB::connection('tenant')->table('item_batches')->where('item_id', $itemId)->where('qty', '>', 0)->get()
            ->sort(fn ($a, $b) => strcmp($a->expiry_date ?: '9999-12-31', $b->expiry_date ?: '9999-12-31') ?: $a->id <=> $b->id)->values();
    }

    public function batchTotal(int $itemId): string
    {
        return self::q(DB::connection('tenant')->table('item_batches')->where('item_id', $itemId)->where('qty', '>', 0)->sum('qty'));
    }

    /** Units on hand that are not in any batch. */
    public function pool(int $itemId): string
    {
        $q = self::qsub(Item::where('id', $itemId)->value('quantity_on_hand') ?? 0, $this->batchTotal($itemId));

        return self::qcmp($q, 0) < 0 ? '0.000' : $q;
    }

    /** When batches hold more than is on hand (stock fell without a batch chosen), take the difference from expired batches first, then the earliest expiry. */
    public function fit(int $itemId): void
    {
        $over = self::qsub($this->batchTotal($itemId), Item::where('id', $itemId)->value('quantity_on_hand') ?? 0);
        if (self::qcmp($over, 0) <= 0) {
            return;
        }
        $today = now()->toDateString();
        $list = $this->batches($itemId)->sort(function ($a, $b) use ($today) {
            $xa = $a->expiry_date && $a->expiry_date < $today ? 0 : 1;
            $xb = $b->expiry_date && $b->expiry_date < $today ? 0 : 1;

            return ($xa <=> $xb) ?: strcmp($a->expiry_date ?: '9999-12-31', $b->expiry_date ?: '9999-12-31') ?: $a->id <=> $b->id;
        });
        foreach ($list as $b) {
            if (self::qcmp($over, 0) <= 0) {
                break;
            }
            $take = self::qcmp($b->qty, $over) < 0 ? self::q($b->qty) : $over;
            $this->changeBatch($b->id, bcmul($take, '-1', 3));
            $this->move($b->id, $itemId, 'fit', bcmul($take, '-1', 3));
            $over = self::qsub($over, $take);
        }
    }

    /** Give units that have no batch a batch number and expiry date. */
    public function addBatch(Item $item, string $batchNo, ?string $expiry, string $qty): void
    {
        $this->fit($item->id);
        $batchNo = trim($batchNo);
        $qty = str_replace(',', '', trim($qty));
        if ($batchNo === '' || mb_strlen($batchNo) > 60) {
            $this->fail('batch', 'Type the batch number (up to 60 letters).');
        }
        $expiry = $this->validDate($expiry);
        if (! is_numeric($qty) || (float) $qty <= 0) {
            $this->fail('batch', 'Enter how many units are in this batch.');
        }
        $pool = $this->pool($item->id);
        if (self::qcmp($qty, $pool) > 0) {
            $this->fail('batch', 'Only '.self::text($pool).' units have no batch yet. Record the purchase (bill) or a stock adjustment first, then give them a batch here.');
        }

        DB::connection('tenant')->transaction(function () use ($item, $batchNo, $expiry, $qty) {
            $same = DB::connection('tenant')->table('item_batches')->where('item_id', $item->id)->where('batch_no', $batchNo)
                ->when($expiry, fn ($q) => $q->where('expiry_date', $expiry), fn ($q) => $q->whereNull('expiry_date'))->first();
            if ($same) {
                $this->changeBatch($same->id, $qty);
                $id = $same->id;
            } else {
                $id = DB::connection('tenant')->table('item_batches')->insertGetId(['item_id' => $item->id, 'batch_no' => $batchNo, 'expiry_date' => $expiry, 'qty' => $qty, 'created_at' => now()]);
            }
            $this->move($id, $item->id, 'assign', $qty);
        });
        ActivityLog::add("Batch $batchNo (".self::text($qty).($expiry ? ", expires $expiry" : '').") given to item #{$item->id}");
    }

    public function editBatch(int $batchId, string $batchNo, ?string $expiry): void
    {
        $batchNo = trim($batchNo);
        if ($batchNo === '' || mb_strlen($batchNo) > 60) {
            $this->fail('batch', 'Type the batch number.');
        }
        DB::connection('tenant')->table('item_batches')->where('id', $batchId)->update(['batch_no' => $batchNo, 'expiry_date' => $this->validDate($expiry)]);
    }

    /** Move a batch's units back to "no batch". */
    public function releaseBatch(int $batchId): void
    {
        $b = DB::connection('tenant')->table('item_batches')->where('id', $batchId)->first();
        if (! $b || self::qcmp($b->qty, 0) <= 0) {
            return;
        }
        DB::connection('tenant')->transaction(function () use ($b) {
            $this->move($b->id, $b->item_id, 'release', bcmul(self::q($b->qty), '-1', 3));
            DB::connection('tenant')->table('item_batches')->where('id', $b->id)->update(['qty' => 0]);
        });
    }

    protected function changeBatch(int $id, string $delta): void
    {
        DB::connection('tenant')->update('UPDATE item_batches SET qty = qty + ? WHERE id = ?', [$delta, $id]);
    }

    protected function move(int $batchId, int $itemId, string $doc, string $qty): void
    {
        DB::connection('tenant')->table('batch_moves')->insert(['batch_id' => $batchId, 'item_id' => $itemId, 'doc' => $doc, 'doc_id' => null, 'line_id' => null, 'qty' => $qty, 'date' => now()->toDateString(), 'created_at' => now()]);
    }

    protected function validDate(?string $d): ?string
    {
        $d = trim((string) $d);
        if ($d === '') {
            return null;
        }
        $x = \DateTime::createFromFormat('Y-m-d', $d);
        if (! $x || $x->format('Y-m-d') !== $d) {
            $this->fail('batch', 'Enter a real expiry date.');
        }

        return $d;
    }

    // ---- stock adjustments ----------------------------------------------------------

    public function adjustmentsAvailable(): bool
    {
        return Tables::has('stock_adjustments') && Tables::has('stock_adjustment_items');
    }

    public function label($number): string
    {
        return 'SA-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public function adjustments()
    {
        return DB::connection('tenant')->table('stock_adjustments')->orderByDesc('date')->orderByDesc('id')->get()
            ->each(function ($a) {
                $a->label = $this->label($a->number);
                $a->line_count = DB::connection('tenant')->table('stock_adjustment_items')->where('adjustment_id', $a->id)->count();
            });
    }

    public function adjustment(int $id): ?object
    {
        $a = DB::connection('tenant')->table('stock_adjustments')->where('id', $id)->first();
        if (! $a) {
            return null;
        }
        $a->label = $this->label($a->number);
        $a->items = DB::connection('tenant')->table('stock_adjustment_items as i')->leftJoin('items', 'items.id', '=', 'i.item_id')
            ->where('i.adjustment_id', $id)->orderBy('i.sort_order')->get(['i.*', 'items.name as item_name', 'items.sku as item_sku']);

        return $a;
    }

    public function inventoryItems()
    {
        return Item::where('type', 'inventory')->where('status', 1)->orderBy('name')->get(['id', 'name', 'sku', 'quantity_on_hand', 'purchase_cost']);
    }

    /**
     * The account the difference is posted to: the one picked in Item Settings, else an existing inventory/stock
     * adjustment or shrinkage account, else a new "Inventory Adjustments" next to the cost of goods sold account.
     */
    public function adjustmentLedger(): ?int
    {
        $s = $this->items->settings();
        if (! empty($s->adjustment_ledger_id) && Ledger::where('id', $s->adjustment_ledger_id)->exists()) {
            return (int) $s->adjustment_ledger_id;
        }
        $ledger = null;
        foreach (['inventory adjustment', 'stock adjustment', 'shrinkage'] as $name) {
            if ($ledger = Ledger::where('name', 'like', "%$name%")->first()) {
                break;
            }
        }
        if (! $ledger) {
            $sibling = (! empty($s->cogs_ledger_id) ? Ledger::find($s->cogs_ledger_id) : null)
                ?? Ledger::where('code', 'like', '04-%')->where('op_balance_dc', 'D')->where('type', 0)->orderBy('code')->first();
            if (! $sibling) {
                return null;
            }
            $ledger = Ledger::create([
                'group_id' => $sibling->group_id, 'name' => 'Inventory Adjustments', 'code' => $this->chart->nextLedgerCode($sibling->group_id), 'op_balance' => '0.00',
                'op_balance_dc' => 'D', 'type' => 0, 'reconciliation' => 0, 'notes' => 'Stock written off or found in stock counts (created automatically)',
            ]);
            ActivityLog::add('Created ledger "Inventory Adjustments" for stock adjustments');
        }
        $this->items->saveSettings(['adjustment_ledger_id' => $ledger->id]);

        return (int) $ledger->id;
    }

    /**
     * Post an adjustment. $lines: [['item_id', 'new_qty'], ...] - the quantity that is really there now.
     * Lines where nothing changes are skipped. The change is valued at the item's average cost and posted as
     * a journal entry between Inventory Asset and the adjustments account.
     */
    public function createAdjustment(string $date, string $reason, ?string $notes, array $lines): object
    {
        $this->entries->guard($date);
        $reason = isset(self::REASONS[$reason]) ? $reason : 'other';

        $clean = [];
        $seen = [];
        foreach ($lines as $l) {
            $itemId = (int) ($l['item_id'] ?? 0);
            $new = str_replace(',', '', trim((string) ($l['new_qty'] ?? '')));
            if (! $itemId || $new === '') {
                continue;
            }
            if (! is_numeric($new) || (float) $new < 0) {
                $this->fail('lines', "Quantities can't be negative.");
            }
            if (isset($seen[$itemId])) {
                $this->fail('lines', 'The same item is listed twice. Put it on one line.');
            }
            $seen[$itemId] = true;
            $item = Item::find($itemId);
            if (! $item || $item->type !== 'inventory') {
                $this->fail('lines', 'Only Inventory items have stock to adjust.');
            }
            $clean[] = [$item, $new];
        }
        if (! $clean) {
            $this->fail('lines', 'Add at least one item with its new quantity.');
        }

        $this->items->ensureInventoryAccounts();
        $settings = $this->items->settings();
        if (empty($settings->inventory_asset_ledger_id)) {
            $this->fail('lines', 'The Inventory Asset account is not set. Set it under Item Settings first.');
        }
        $adjLedger = $this->adjustmentLedger();
        if (! $adjLedger) {
            $this->fail('lines', 'No Inventory Adjustments account could be found or created. Pick one under Item Settings first.');
        }
        $journal = $this->entries->typeByLabel('journal');

        return DB::connection('tenant')->transaction(function () use ($clean, $date, $reason, $notes, $settings, $adjLedger, $journal) {
            $rows = [];
            $total = '0.00';
            foreach ($clean as [$item, $newQty]) {
                // re-read under a row lock so a sale posted at the same moment can't make "before" stale
                $cur = DB::connection('tenant')->selectOne('SELECT id, quantity_on_hand, purchase_cost FROM items WHERE id = ? FOR UPDATE', [$item->id]);
                $change = bcsub(number_format((float) $newQty, 2, '.', ''), number_format((float) $cur->quantity_on_hand, 2, '.', ''), 2);
                if (bccomp($change, '0', 2) === 0) {
                    continue;
                }
                // value of the change at average cost, rounded half away from zero to cents
                $p = bcmul($change, (string) $cur->purchase_cost, 6);
                $value = bcadd($p, bccomp($p, '0', 6) < 0 ? '-0.005' : '0.005', 2);
                $total = Money::add($total, $value);
                $rows[] = ['item_id' => $cur->id, 'qty_before' => $cur->quantity_on_hand, 'qty_after' => number_format((float) $newQty, 2, '.', ''), 'qty_change' => $change, 'unit_cost' => $cur->purchase_cost, 'value' => $value];
            }
            if (! $rows) {
                $this->fail('lines', "Nothing changed - every quantity is the same as what's already on hand.");
            }

            $number = ((int) DB::connection('tenant')->table('stock_adjustments')->max('number')) + 1;
            $label = $this->label($number);
            $reasonText = self::REASONS[$reason];
            $entryId = null;
            if (! Money::isZero($total)) {
                $abs = ltrim($total, '-');
                $up = Money::positive($total);
                $narr = "Stock adjustment $label - $reasonText";
                $entry = Entry::create(['entrytype_id' => $journal->id, 'tag_id' => null, 'number' => $this->entries->nextNumber($journal->id), 'date' => $date,
                    'dr_total' => $abs, 'cr_total' => $abs, 'notes' => $narr.($notes ? " ($notes)" : '')]);
                DB::connection('tenant')->table('entryitems')->insert([
                    ['entry_id' => $entry->id, 'ledger_id' => $up ? $settings->inventory_asset_ledger_id : $adjLedger, 'amount' => $abs, 'dc' => 'D', 'narration' => $narr],
                    ['entry_id' => $entry->id, 'ledger_id' => $up ? $adjLedger : $settings->inventory_asset_ledger_id, 'amount' => $abs, 'dc' => 'C', 'narration' => $narr],
                ]);
                $entryId = $entry->id;
            }
            $adjId = DB::connection('tenant')->table('stock_adjustments')->insertGetId([
                'number' => $number, 'date' => $date, 'reason' => $reason, 'notes' => $notes ? mb_substr($notes, 0, 500) : null, 'status' => 'posted',
                'total_value' => $total, 'entry_id' => $entryId, 'created_at' => now(),
            ]);
            foreach ($rows as $i => $r) {
                DB::connection('tenant')->table('stock_adjustment_items')->insert($r + ['adjustment_id' => $adjId, 'sort_order' => $i]);
                DB::connection('tenant')->table('items')->where('id', $r['item_id'])->update(['quantity_on_hand' => $r['qty_after']]);
            }
            ActivityLog::add("Posted stock adjustment $label ($reasonText, ".count($rows).' item'.(count($rows) === 1 ? '' : 's').')');

            return (object) ['id' => $adjId, 'label' => $label, 'count' => count($rows)];
        });
    }

    /** Undo an adjustment: each item's quantity moves back by exactly what it changed (sales and purchases since are kept) and its journal entry is removed. */
    public function voidAdjustment(int $id): void
    {
        $adj = $this->adjustment($id) ?? $this->fail('adjustment', 'Adjustment not found.');
        $this->entries->guard(substr((string) $adj->date, 0, 10));
        if ($adj->status !== 'posted') {
            $this->fail('adjustment', 'This adjustment is already void.');
        }

        DB::connection('tenant')->transaction(function () use ($adj, $id) {
            $locked = DB::connection('tenant')->selectOne('SELECT status FROM stock_adjustments WHERE id = ? FOR UPDATE', [$id]);
            if (! $locked || $locked->status !== 'posted') {
                $this->fail('adjustment', 'This adjustment is already void.');
            }
            // stock can't go below 0: undoing an adjustment that ADDED stock is refused when that stock has been sold since
            $short = [];
            foreach ($adj->items as $l) {
                if (bccomp((string) $l->qty_change, '0', 2) > 0) {
                    $cur = DB::connection('tenant')->selectOne('SELECT name, quantity_on_hand FROM items WHERE id = ? FOR UPDATE', [$l->item_id]);
                    if ($cur && bccomp((string) $cur->quantity_on_hand, (string) $l->qty_change, 2) < 0) {
                        $short[] = "{$cur->name} ({$cur->quantity_on_hand} in stock, needs {$l->qty_change})";
                    }
                }
            }
            if ($short) {
                $this->fail('adjustment', 'Not enough stock: '.implode('; ', $short).'. The adjustment was not voided. Those items were already sold. Make a new adjustment instead.');
            }
            foreach ($adj->items as $l) {
                DB::connection('tenant')->update('UPDATE items SET quantity_on_hand = quantity_on_hand - ? WHERE id = ?', [$l->qty_change, $l->item_id]);
            }
            DB::connection('tenant')->table('stock_adjustments')->where('id', $id)->update(['status' => 'void', 'entry_id' => null]);
            if (! empty($adj->entry_id)) {
                DB::connection('tenant')->table('entryitems')->where('entry_id', $adj->entry_id)->delete();
                DB::connection('tenant')->table('entries')->where('id', $adj->entry_id)->delete();
            }
            ActivityLog::add("Voided stock adjustment {$adj->label}");
        });
    }

    protected function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
