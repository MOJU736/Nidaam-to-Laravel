<?php

namespace App\Services\Construction;

use App\Models\Tenant\EntryType;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Services\Inventory\ItemService;
use App\Services\Payroll\PayrollService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Construction - materials and site stock (legacy Site_stock_model). Stores: the main store (id 0, not a row) plus site stores. Only site
 * stores keep quantities (store_stock); the main store holds the rest (item quantity on hand less what sits on the sites), so bills, sales
 * and adjustments keep working on the item total as before.
 *
 *   Transfer (main -> site, site -> site, site -> main): the stock only moves; nothing is posted.
 *   Issue on site (from any store, to a project): Dr Site Materials Used (tagged project + Materials) / Cr Inventory Asset at the item's
 *   cost; the quantity leaves the store and the item. Stock never goes below 0 in any store. Void puts everything back.
 */
class SiteStockService
{
    public function __construct(protected ChartOfAccounts $chart, protected EntryService $entries, protected ItemService $items, protected PayrollService $ledgers) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['projects' => $message]);
    }

    public function stores(bool $activeOnly = false)
    {
        return $this->db()->table('stores')->leftJoin('projects', 'projects.id', '=', 'stores.project_id')->when($activeOnly, fn ($q) => $q->where('stores.status', 'active'))
            ->orderBy('stores.name')->get(['stores.*', 'projects.name as project_name', 'projects.number as project_number']);
    }

    public function storeName(int $id): string
    {
        return $id === 0 ? 'Main store' : ($this->db()->table('stores')->where('id', $id)->value('name') ?? "Store $id");
    }

    public function addStore(array $d): int
    {
        $name = trim((string) ($d['name'] ?? ''));
        $pid = ! empty($d['project_id']) ? (int) $d['project_id'] : null;
        if ($name === '' && $pid && ($p = $this->db()->table('projects')->where('id', $pid)->first(['number', 'name']))) {
            $name = "Site: #{$p->number} {$p->name}";
        }
        if ($name === '') {
            $this->fail('Give the store a name, or choose its project.');
        }
        if ($this->db()->table('stores')->where('name', $name)->exists()) {
            $this->fail("There is already a store called \"$name\".");
        }
        $id = $this->db()->table('stores')->insertGetId(['name' => $name, 'project_id' => $pid, 'status' => 'active', 'created_at' => now()]);
        ActivityLog::add("Added store $name");

        return $id;
    }

    /** Qty of one item in one store (0 = main store = total less what is on the sites). */
    public function qtyIn(int $storeId, int $itemId): string
    {
        if ($storeId === 0) {
            $total = $this->db()->table('items')->where('id', $itemId)->value('quantity_on_hand') ?? 0;

            return Money::sub($total, $this->db()->table('store_stock')->where('item_id', $itemId)->sum('qty'));
        }

        return Money::add($this->db()->table('store_stock')->where('store_id', $storeId)->where('item_id', $itemId)->value('qty') ?? 0, 0);
    }

    protected function addQty(int $storeId, int $itemId, string $delta): void
    {
        if ($storeId === 0) {
            return;
        }
        $row = $this->db()->table('store_stock')->where('store_id', $storeId)->where('item_id', $itemId)->first();
        $row ? $this->db()->table('store_stock')->where('id', $row->id)->update(['qty' => Money::add($row->qty, $delta)])
            : $this->db()->table('store_stock')->insert(['store_id' => $storeId, 'item_id' => $itemId, 'qty' => $delta]);
    }

    /** Every stock item with its quantity per store. */
    public function stockTable(): array
    {
        $stores = $this->stores(true);
        $bySite = [];
        foreach ($this->db()->table('store_stock')->get() as $r) {
            $bySite[(int) $r->item_id][(int) $r->store_id] = $r->qty;
        }
        $rows = [];
        foreach ($this->db()->table('items')->where('type', 'inventory')->orderBy('name')->get(['id', 'name', 'quantity_on_hand', 'purchase_cost']) as $it) {
            $sites = $bySite[(int) $it->id] ?? [];
            $onSites = array_reduce($sites, fn ($c, $q) => Money::add($c, $q), '0.00');
            if (Money::isZero($it->quantity_on_hand) && Money::isZero($onSites)) {
                continue;
            }
            $it->main = Money::sub($it->quantity_on_hand, $onSites);
            $it->sites = $sites;
            $rows[] = $it;
        }

        return ['stores' => $stores, 'rows' => $rows];
    }

    public function moves(int $limit = 100)
    {
        return $this->db()->table('stock_moves as m')->leftJoin('items as i', 'i.id', '=', 'm.item_id')->leftJoin('projects as p', 'p.id', '=', 'm.project_id')
            ->orderByDesc('m.date')->orderByDesc('m.id')->limit($limit)->get(['m.*', 'i.name as item_name', 'p.name as project_name', 'p.number as project_number']);
    }

    protected function nextNumber(): int
    {
        return (int) $this->db()->table('stock_moves')->max('number') + 1;
    }

    /** [item row, qty string] for a move, or a refusal. */
    protected function common(array $d): array
    {
        $item = $this->db()->table('items')->where('id', (int) ($d['item_id'] ?? 0))->first();
        if (! $item || $item->type !== 'inventory') {
            $this->fail('Choose a stock item.');
        }
        $qty = trim(str_replace(',', '.', (string) ($d['qty'] ?? '')));
        if ($qty === '' || ! is_numeric($qty) || (float) $qty <= 0) {
            $this->fail('Enter a quantity more than 0.');
        }
        if (empty($d['date'])) {
            $this->fail('Enter the date.');
        }

        return [$item, number_format((float) $qty, 2, '.', '')];
    }

    protected function storeOk(int $id): bool
    {
        return $id === 0 || $this->db()->table('stores')->where('id', $id)->where('status', 'active')->exists();
    }

    protected function short(object $item, int $from, string $have, string $qty): never
    {
        $this->fail("Not enough {$item->name} in {$this->storeName($from)}: ".rtrim(rtrim($have, '0'), '.').' there, '.rtrim(rtrim($qty, '0'), '.').' asked.');
    }

    public function transfer(array $d): int
    {
        [$item, $qty] = $this->common($d);
        $from = (int) ($d['from_store_id'] ?? 0);
        $to = (int) ($d['to_store_id'] ?? 0);
        if ($from === $to) {
            $this->fail('Choose two different stores.');
        }
        if (! $this->storeOk($from) || ! $this->storeOk($to)) {
            $this->fail('Choose an active store.');
        }
        $id = $this->db()->transaction(function () use ($d, $item, $qty, $from, $to) {
            $have = $this->qtyIn($from, (int) $item->id);
            if (Money::cmp($qty, $have) > 0) {
                $this->short($item, $from, $have, $qty);
            }
            $this->addQty($from, (int) $item->id, Money::neg($qty));
            $this->addQty($to, (int) $item->id, $qty);

            return $this->db()->table('stock_moves')->insertGetId(['number' => $this->nextNumber(), 'kind' => 'transfer', 'date' => $d['date'], 'item_id' => $item->id, 'qty' => $qty, 'from_store_id' => $from,
                'to_store_id' => $to, 'notes' => isset($d['notes']) ? trim((string) $d['notes']) : null, 'status' => 'posted', 'created_at' => now()]);
        });
        ActivityLog::add("Moved $qty {$item->name} from {$this->storeName($from)} to {$this->storeName($to)}");

        return $id;
    }

    public function issue(array $d): int
    {
        if ($this->chart->companyLocked()) {
            $this->fail('This company is locked, so nothing can be posted. An administrator can unlock it under Settings.');
        }
        [$item, $qty] = $this->common($d);
        if ($err = $this->entries->closedPeriodError($d['date'])) {
            $this->fail($err);
        }
        $from = (int) ($d['from_store_id'] ?? 0);
        $this->storeOk($from) || $this->fail('Choose an active store.');
        $project = $this->db()->table('projects')->where('id', (int) ($d['project_id'] ?? 0))->first() ?? $this->fail('Choose the project the materials are used on.');
        $this->items->ensureInventoryAccounts();
        $inventory = $this->items->settings()->inventory_asset_ledger_id ?? null;
        $inventory || $this->fail('The Inventory Asset account is missing (Items → Settings).');
        $used = $this->ledgers->ledgerFor('materials_used');
        $et = EntryType::where('label', 'journal')->first() ?? $this->fail('The Journal entry type is missing. Check Settings → Entry types.');
        $amount = Money::mul($qty, $item->purchase_cost);

        $id = $this->db()->transaction(function () use ($d, $item, $qty, $from, $project, $inventory, $used, $et, $amount) {
            $fresh = $this->db()->selectOne('SELECT quantity_on_hand FROM items WHERE id = ? FOR UPDATE', [$item->id]);
            $have = $this->qtyIn($from, (int) $item->id);
            if (Money::cmp($qty, $have) > 0 || Money::cmp($qty, $fresh->quantity_on_hand) > 0) {
                $this->short($item, $from, $have, $qty);
            }
            $number = $this->nextNumber();
            $label = "Issued to site $number: ".rtrim(rtrim($qty, '0'), '.')." x {$item->name} - project #{$project->number} {$project->name}";
            $entryId = null;
            if (Money::positive($amount)) {
                $entryId = $this->db()->table('entries')->insertGetId(['entrytype_id' => $et->id, 'tag_id' => null, 'number' => $this->entries->nextNumber($et->id), 'date' => $d['date'], 'dr_total' => $amount, 'cr_total' => $amount, 'notes' => $label]);
                $this->db()->table('entryitems')->insert([
                    ['entry_id' => $entryId, 'ledger_id' => $used->id, 'amount' => $amount, 'dc' => 'D', 'narration' => $label, 'project_id' => $project->id, 'cost_type' => 'materials'],
                    ['entry_id' => $entryId, 'ledger_id' => $inventory, 'amount' => $amount, 'dc' => 'C', 'narration' => $label, 'project_id' => null, 'cost_type' => null],
                ]);
            }
            $this->addQty($from, (int) $item->id, Money::neg($qty));
            $this->db()->table('items')->where('id', $item->id)->update(['quantity_on_hand' => Money::sub($fresh->quantity_on_hand, $qty)]);
            $mid = $this->db()->table('stock_moves')->insertGetId(['number' => $number, 'kind' => 'issue', 'date' => $d['date'], 'item_id' => $item->id, 'qty' => $qty, 'from_store_id' => $from, 'to_store_id' => null,
                'project_id' => $project->id, 'unit_cost' => $item->purchase_cost, 'amount' => $amount, 'entry_id' => $entryId, 'notes' => isset($d['notes']) ? trim((string) $d['notes']) : null, 'status' => 'posted', 'created_at' => now()]);
            ActivityLog::add($label);

            return $mid;
        });

        return $id;
    }

    public function voidMove(int $id): void
    {
        $m = $this->db()->table('stock_moves')->where('id', $id)->first();
        if (! $m || $m->status !== 'posted') {
            $this->fail('That movement is already void.');
        }
        if ($m->kind === 'issue') {
            if ($this->chart->companyLocked()) {
                $this->fail('This company is locked, so nothing can be changed. An administrator can unlock it under Settings.');
            }
            if ($err = $this->entries->closedPeriodError($m->date)) {
                $this->fail($err);
            }
        }
        $this->db()->transaction(function () use ($id, $m) {
            if ($m->kind === 'transfer') {
                $have = $this->qtyIn((int) $m->to_store_id, (int) $m->item_id);
                if (Money::cmp($m->qty, $have) > 0) {
                    $this->fail("Part of it was already used or moved on from {$this->storeName((int) $m->to_store_id)}, so this transfer can't be undone. Move the rest back with a new transfer.");
                }
                $this->addQty((int) $m->to_store_id, (int) $m->item_id, Money::neg($m->qty));
                $this->addQty((int) $m->from_store_id, (int) $m->item_id, $m->qty);
            } else {
                $this->addQty((int) $m->from_store_id, (int) $m->item_id, $m->qty);
                $this->db()->update('UPDATE items SET quantity_on_hand = quantity_on_hand + ? WHERE id = ?', [$m->qty, $m->item_id]);
                if ($m->entry_id) {
                    $this->db()->table('entryitems')->where('entry_id', $m->entry_id)->delete();
                    $this->db()->table('entries')->where('id', $m->entry_id)->delete();
                }
            }
            $this->db()->table('stock_moves')->where('id', $id)->update(['status' => 'void', 'entry_id' => null]);
        });
        ActivityLog::add("Voided stock movement {$m->number}");
    }
}
