<?php

namespace App\Services\Inventory;

use App\Models\Tenant\CustomerVehicle;
use App\Models\Tenant\Group;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemCategory;
use App\Models\Tenant\ItemVehicleModelPrice;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\SalesSetting;
use App\Models\Tenant\VehicleModel;
use App\Services\Accounting\ChartOfAccounts;
use App\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The item catalog: items (service, inventory, non-inventory), categories, the item accounts
 * settings and special prices per vehicle model. Ported from the legacy Items_model.
 *
 * Quantity on hand and the average cost of an inventory item are not edited here: they are rolled
 * up by bills, invoices and stock adjustments.
 */
class ItemService
{
    const TYPES = ['service' => 'Service', 'inventory' => 'Inventory', 'non_inventory' => 'Non-inventory'];

    public function __construct(protected ChartOfAccounts $chart) {}

    // ---- accounts the item forms may pick -----------------------------------

    /** Non-bank ledgers under a top group ('income' | 'expense'), or the whole chart for null: [id => "code - name"]. */
    public function ledgerOptions(?string $root): array
    {
        $rootId = $root ? $this->chart->topLevelGroupId($root) : null;
        $q = Ledger::where(fn ($w) => $w->where('type', '!=', 1)->orWhereNull('type'))->orderBy('code')->orderBy('name');
        // a chart that was customised away from the seeded groups still gets a usable picker
        if ($rootId) {
            $q->whereIn('id', $this->chart->descendantLedgerIds($rootId));
        }
        // customers' and vendors' own accounts are never an item account
        $party = array_merge(DB::connection('tenant')->table('customers')->pluck('ledger_id')->all(), DB::connection('tenant')->table('vendors')->pluck('ledger_id')->all());

        return $q->whereNotIn('id', $party)->get(['id', 'name', 'code'])->mapWithKeys(fn ($l) => [$l->id => trim(($l->code ? $l->code.' - ' : '').$l->name)])->all();
    }

    /** Default accounts for a new item that picked none: the company's sales account; "Purchase Account" / "Service Account" for expense. */
    public function defaults(string $type): array
    {
        $expense = match ($type) {
            'non_inventory' => Ledger::where('name', 'Purchase Account')->value('id'),
            'service' => Ledger::where('name', 'Service Account')->value('id'),
            default => null,
        };

        return ['sales_ledger_id' => SalesSetting::find(1)?->sales_ledger_id, 'expense_ledger_id' => $expense];
    }

    // ---- items --------------------------------------------------------------

    public function list(?int $categoryId = null)
    {
        return Item::query()->leftJoin('item_categories', 'item_categories.id', '=', 'items.category_id')
            ->when($categoryId, fn ($q) => $q->where('items.category_id', $categoryId))
            ->orderBy('items.name')->get(['items.*', 'item_categories.name as category_name']);
    }

    /**
     * Validate one row of the "add items" table. Returns the clean attributes or an array of error strings.
     *
     * @return array{0: ?array, 1: array}
     */
    public function cleanRow(array $r, array $income, array $expense, array $categories, int $decimals): array
    {
        $errors = [];
        $type = array_key_exists($r['type'] ?? '', self::TYPES) ? $r['type'] : 'service';
        $d = $this->defaults($type);
        $sales = ($r['sales_ledger_id'] ?? '') !== '' ? $r['sales_ledger_id'] : (string) ($d['sales_ledger_id'] ?? '');
        $exp = ($r['expense_ledger_id'] ?? '') !== '' ? $r['expense_ledger_id'] : (string) ($d['expense_ledger_id'] ?? '');
        $name = trim((string) ($r['name'] ?? ''));

        if ($name === '') {
            $errors[] = 'Item name is required.';
        }
        if ($sales === '' || ! array_key_exists($sales, $income)) {
            $errors[] = 'Please choose an Income account.';
        }
        if ($exp !== '' && ! array_key_exists($exp, $expense)) {
            $errors[] = 'Invalid Expense account.';
        }
        $cat = ($r['category_id'] ?? '') !== '' ? (int) $r['category_id'] : null;
        if ($cat && ! isset($categories[$cat])) {
            $errors[] = 'Invalid category.';
        }
        foreach (['sales_price' => 'Sales price', 'purchase_cost' => 'Purchase cost', 'quantity_on_hand' => 'Quantity on hand'] as $f => $label) {
            $v = str_replace(',', '', trim((string) ($r[$f] ?? '')));
            if ($v !== '' && (! is_numeric($v) || (float) $v < 0)) {
                $errors[] = "$label must be a number, zero or more.";
            }
            $r[$f] = $v === '' ? '0.00' : $v;
        }

        if ($errors) {
            return [null, $errors];
        }

        return [[
            'type' => $type, 'category_id' => $cat, 'sku' => trim((string) ($r['sku'] ?? '')) ?: null, 'name' => $name,
            'description' => (string) ($r['description'] ?? ''), 'sales_price' => $r['sales_price'], 'sales_ledger_id' => (int) $sales,
            'expense_ledger_id' => $exp !== '' ? (int) $exp : null, 'purchase_cost' => $r['purchase_cost'],
            // a starting quantity means nothing for services / non-inventory
            'quantity_on_hand' => $type === 'inventory' ? $r['quantity_on_hand'] : '0.00',
        ], []];
    }

    public function create(array $a): Item
    {
        $item = Item::create($a + ['status' => 1, 'created_at' => now()]);
        if ($a['type'] === 'inventory') {
            $this->ensureInventoryAccounts();
        }

        return $item;
    }

    public function update(Item $item, array $a): void
    {
        $item->update($a);
        if ($a['type'] === 'inventory') {
            $this->ensureInventoryAccounts();
        }
    }

    /** Blocked once the item is on a quotation, invoice, bill or stock adjustment - mark it inactive instead. */
    public function delete(Item $item): void
    {
        $used = 0;
        foreach (['quotation_items', 'invoice_items', 'bill_items', 'stock_adjustment_items'] as $t) {
            if (Tables::has($t, 'item_id')) {
                $used += DB::connection('tenant')->table($t)->where('item_id', $item->id)->count();
            }
        }
        if ($used > 0) {
            throw ValidationException::withMessages(['item' => 'This item has been used on a quotation, invoice, bill or stock adjustment and cannot be deleted. Mark it inactive instead.']);
        }
        DB::connection('tenant')->transaction(function () use ($item) {
            ItemVehicleModelPrice::where('item_id', $item->id)->delete();
            $item->delete();
        });
    }

    // ---- inventory accounts --------------------------------------------------

    public function settings(): object
    {
        $row = DB::connection('tenant')->table('item_settings')->where('id', 1)->first();

        return $row ?? (object) ['inventory_asset_ledger_id' => null, 'cogs_ledger_id' => null, 'adjustment_ledger_id' => null];
    }

    public function saveSettings(array $d): void
    {
        $cols = array_filter($d, fn ($v, $k) => Tables::has('item_settings', $k), ARRAY_FILTER_USE_BOTH);
        $db = DB::connection('tenant')->table('item_settings');
        $db->where('id', 1)->exists() ? $db->where('id', 1)->update($cols) : $db->insert(['id' => 1] + $cols);
    }

    /**
     * Stock needs an Inventory Asset and a Cost of Goods Sold account. A company set up without stock gets them the
     * first time an inventory item is saved: an existing ledger by name, else a new one.
     */
    public function ensureInventoryAccounts(): void
    {
        $s = $this->settings();
        $valid = fn ($id) => $id && Ledger::where('id', $id)->exists();
        $upd = [];

        if (! $valid($s->inventory_asset_ledger_id)) {
            $l = $this->findLedger(['inventory', 'stock'], ['expense', 'cost of']) ?? $this->makeLedger('Inventory Asset', 'Value of stock on hand', 'asset');
            $l && $upd['inventory_asset_ledger_id'] = $l->id;
        }
        if (! $valid($s->cogs_ledger_id)) {
            $l = $this->findLedger(['cost of goods', 'cost of sales', 'cogs'], []) ?? $this->makeLedger('Cost of Goods Sold', 'Cost of stock sold on invoices', 'expense');
            $l && $upd['cogs_ledger_id'] = $l->id;
        }
        $upd && $this->saveSettings($upd);
    }

    protected function findLedger(array $patterns, array $exclude): ?Ledger
    {
        foreach ($patterns as $p) {
            $q = Ledger::where('type', 0)->where('name', 'like', "%$p%");
            foreach ($exclude as $ex) {
                $q->where('name', 'not like', "%$ex%");
            }
            if ($l = $q->orderBy('code')->first()) {
                return $l;
            }
        }

        return null;
    }

    /** A new account in a sensible group: the stock/inventory group (assets) or the "cost of" group (expenses), else the top group. */
    protected function makeLedger(string $name, string $notes, string $kind): ?Ledger
    {
        $root = $this->chart->topLevelGroupId($kind === 'asset' ? 'asset' : 'expense') ?? Group::whereNull('parent_id')->where('name', 'like', $kind === 'asset' ? 'Asset%' : 'Expense%')->value('id');
        if (! $root) {
            return null;
        }
        $under = array_merge([$root], Group::where('code', 'like', Group::find($root)->code.'-%')->pluck('id')->all());
        $g = Group::whereIn('id', $under)->where(fn ($q) => $kind === 'asset' ? $q->where('name', 'like', '%inventor%')->orWhere('name', 'like', '%stock%') : $q->where('name', 'like', 'cost of%'))->first()
            ?? ($kind === 'asset' ? Group::whereIn('id', $under)->where('name', 'like', '%current asset%')->first() : null)
            ?? Group::find($root);

        return Ledger::create(['group_id' => $g->id, 'name' => $name, 'code' => $this->chart->nextLedgerCode($g->id), 'op_balance' => '0.00', 'op_balance_dc' => 'D', 'type' => 0, 'reconciliation' => 0, 'notes' => $notes]);
    }

    // ---- categories ----------------------------------------------------------

    public function categoryOptions(): array
    {
        return ItemCategory::orderBy('name')->pluck('name', 'id')->all();
    }

    public function saveCategory(?ItemCategory $c, string $name): ItemCategory
    {
        $name = trim($name);
        if ($name === '') {
            $this->fail('category', 'Enter a category name.');
        }
        if (ItemCategory::where('name', $name)->when($c, fn ($q) => $q->where('id', '!=', $c->id))->exists()) {
            $this->fail('category', "A category named \"$name\" already exists.");
        }
        if ($c) {
            $c->update(['name' => $name]);

            return $c;
        }

        return ItemCategory::create(['name' => $name, 'created_at' => now()]);
    }

    /** Items tagged with it just go back to uncategorized. */
    public function deleteCategory(ItemCategory $c): void
    {
        DB::connection('tenant')->transaction(function () use ($c) {
            Item::where('category_id', $c->id)->update(['category_id' => null]);
            $c->delete();
        });
    }

    // ---- vehicle models and their special prices ---------------------------------

    public function saveVehicleModel(?VehicleModel $m, string $name): VehicleModel
    {
        $name = trim($name);
        if ($name === '') {
            $this->fail('vehicle', 'Enter a vehicle model name.');
        }
        if (VehicleModel::where('name', $name)->when($m, fn ($q) => $q->where('id', '!=', $m->id))->exists()) {
            $this->fail('vehicle', "A vehicle model named \"$name\" already exists.");
        }
        if ($m) {
            $m->update(['name' => $name]);

            return $m;
        }

        return VehicleModel::create(['name' => $name, 'created_at' => now()]);
    }

    /** Its special prices go with it, and any customer vehicle tagged with it goes back to untagged. */
    public function deleteVehicleModel(VehicleModel $m): void
    {
        DB::connection('tenant')->transaction(function () use ($m) {
            ItemVehicleModelPrice::where('vehicle_model_id', $m->id)->delete();
            CustomerVehicle::where('vehicle_model_id', $m->id)->update(['vehicle_model_id' => null]);
            $m->delete();
        });
    }

    /** One model's special prices across the whole catalog: item id => unit price. */
    public function vehiclePrices(int $modelId): array
    {
        return ItemVehicleModelPrice::where('vehicle_model_id', $modelId)->pluck('unit_price', 'item_id')->all();
    }

    /** Replace a model's prices in one go: a blank removes the override (the item's normal price applies). */
    public function saveVehiclePrices(VehicleModel $m, array $prices): void
    {
        $valid = Item::pluck('id')->flip();
        DB::connection('tenant')->transaction(function () use ($m, $prices, $valid) {
            foreach ($prices as $itemId => $price) {
                $price = str_replace(',', '', trim((string) $price));
                if (! $valid->has((int) $itemId)) {
                    continue;
                }
                $row = ItemVehicleModelPrice::where('item_id', $itemId)->where('vehicle_model_id', $m->id);
                if ($price === '') {
                    $row->delete();

                    continue;
                }
                if (! is_numeric($price) || (float) $price < 0) {
                    $this->fail('prices', 'Prices must be numbers, zero or more.');
                }
                ItemVehicleModelPrice::updateOrCreate(['item_id' => (int) $itemId, 'vehicle_model_id' => $m->id], ['unit_price' => $price]);
            }
        });
    }

    protected function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
