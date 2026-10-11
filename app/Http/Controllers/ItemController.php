<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Item;
use App\Models\Tenant\ItemCategory;
use App\Services\Accounting\ActivityLog;
use App\Services\Inventory\ItemService;
use App\Services\Inventory\StockService;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Http\Request;

/** The item catalog: list, add several at once, edit, delete. */
class ItemController extends Controller
{
    public function __construct(protected ItemService $items) {}

    public function index(Request $request)
    {
        $category = (int) $request->query('category_id') ?: null;

        return view('items.index', [
            'items' => $this->items->list($category), 'categories' => $this->items->categoryOptions(), 'selected' => $category,
            'income' => $this->items->ledgerOptions('income'), 'expense' => $this->items->ledgerOptions('expense'),
        ]);
    }

    /** A table of rows so several items can be registered at once; rows left completely blank are ignored. */
    public function create()
    {
        $blank = ['type' => 'service', 'category_id' => '', 'sku' => '', 'name' => '', 'description' => '', 'sales_price' => '0.00', 'sales_ledger_id' => (string) ($this->items->defaults('service')['sales_ledger_id'] ?? ''), 'expense_ledger_id' => '', 'purchase_cost' => '0.00', 'quantity_on_hand' => '0.00'];

        return view('items.add', $this->addData(array_fill(0, 3, $blank)));
    }

    public function store(Request $request)
    {
        $income = $this->items->ledgerOptions('income');
        $expense = $this->items->ledgerOptions('expense');
        $cats = $this->items->categoryOptions();
        $rows = array_values((array) $request->input('rows'));
        $clean = [];
        $errors = [];

        foreach ($rows as $i => $r) {
            if (trim((string) ($r['name'] ?? '')) === '' && trim((string) ($r['sku'] ?? '')) === '') {
                continue; // an unused extra line
            }
            [$a, $e] = $this->items->cleanRow($r, $income, $expense, $cats, 2);
            $a ? $clean[] = $a : array_push($errors, ...array_map(fn ($m) => 'Row '.($i + 1).': '.$m, $e));
        }
        if (! $clean && ! $errors) {
            $errors[] = 'Enter at least one item to save.';
        }
        if ($errors) {
            return back()->withInput()->withErrors(['rows' => $errors]);
        }

        foreach ($clean as $a) {
            $this->items->create($a);
        }
        $names = array_column($clean, 'name');
        ActivityLog::add(count($names) === 1 ? "Added Item: {$names[0]}" : 'Added '.count($names).' Items: '.implode(', ', $names));

        return redirect($request->has('save_and_new') ? route('items.create') : route('items.index'))
            ->with('message', count($names) === 1 ? "Item \"{$names[0]}\" was created successfully." : count($names).' items were created successfully.');
    }

    /** JSON "quick add" used from the invoice / bill pickers: a minimal item without leaving the page. */
    public function quick(Request $request)
    {
        $income = $this->items->ledgerOptions('income');
        $expense = $this->items->ledgerOptions('expense');
        [$a, $errors] = $this->items->cleanRow($request->only(['type', 'name', 'description', 'sales_price', 'sales_ledger_id', 'expense_ledger_id', 'purchase_cost', 'quantity_on_hand']), $income, $expense, [], 2);
        if (! $a) {
            return response()->json(['ok' => false, 'error' => implode(' ', $errors)]);
        }
        $item = $this->items->create($a);
        ActivityLog::add("Added Item: {$a['name']}");

        return response()->json(['ok' => true, 'id' => $item->id, 'item' => [
            'name' => $a['name'], 'description' => $a['description'], 'type' => $a['type'], 'sales_price' => $a['sales_price'], 'purchase_cost' => $a['purchase_cost'],
            'quantity_on_hand' => $a['quantity_on_hand'], 'sales_ledger_id' => $a['sales_ledger_id'], 'expense_ledger_id' => $a['expense_ledger_id'], 'vehicle_prices' => new \stdClass,
        ]]);
    }

    public function edit(int $id)
    {
        $item = Item::findOrFail($id);

        return view('items.edit', [
            'item' => $item, 'income' => $this->items->ledgerOptions('income'), 'expense' => $this->items->ledgerOptions('expense'),
            'categories' => $this->items->categoryOptions(), 'batchCols' => Tables::has('items', 'track_batches') && Tables::has('items', 'reorder_level'),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $item = Item::findOrFail($id);
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'type' => ['required', 'in:service,inventory,non_inventory'], 'sku' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'], 'sales_price' => ['nullable', 'numeric', 'min:0'], 'category_id' => ['nullable', 'integer'],
            'sales_ledger_id' => ['nullable', 'integer'], 'expense_ledger_id' => ['nullable', 'integer'], 'reorder_level' => ['nullable', 'numeric', 'min:0'],
        ]);
        $income = $this->items->ledgerOptions('income');
        $expense = $this->items->ledgerOptions('expense');
        $sales = $d['sales_ledger_id'] ?? $this->items->defaults($d['type'])['sales_ledger_id'] ?? null;
        if (! $sales || ! isset($income[$sales])) {
            return back()->withInput()->withErrors(['sales_ledger_id' => 'Please choose an Income account.']);
        }
        if (! empty($d['expense_ledger_id']) && ! isset($expense[$d['expense_ledger_id']])) {
            return back()->withInput()->withErrors(['expense_ledger_id' => 'Invalid Expense account.']);
        }

        $attrs = [
            'type' => $d['type'], 'category_id' => ! empty($d['category_id']) && ItemCategory::whereKey($d['category_id'])->exists() ? $d['category_id'] : null,
            'sku' => $d['sku'] ?? null, 'name' => $d['name'], 'description' => (string) ($d['description'] ?? ''), 'sales_price' => $d['sales_price'] ?? '0.00',
            'sales_ledger_id' => $sales, 'expense_ledger_id' => $d['expense_ledger_id'] ?? null, 'status' => $request->boolean('status') ? 1 : 0,
        ];
        // reorder level and batch tracking only apply to stock items
        if ($d['type'] === 'inventory' && Tables::has('items', 'track_batches') && Tables::has('items', 'reorder_level')) {
            $attrs['reorder_level'] = ($d['reorder_level'] ?? '') !== '' ? $d['reorder_level'] : null;
            $attrs['track_batches'] = $request->boolean('track_batches') ? 1 : 0;
        }
        $this->items->update($item, $attrs);
        ActivityLog::add("Updated Item: {$d['name']}");

        return redirect()->route('items.index')->with('message', "Item \"{$d['name']}\" was updated successfully.");
    }

    public function destroy(int $id)
    {
        $item = Item::findOrFail($id);
        $this->items->delete($item);
        ActivityLog::add("Deleted Item: {$item->name}");

        return redirect()->route('items.index')->with('message', 'Item deleted successfully.');
    }

    protected function addData(array $rows): array
    {
        return ['rows' => $rows, 'income' => $this->items->ledgerOptions('income'), 'expense' => $this->items->ledgerOptions('expense'), 'categories' => $this->items->categoryOptions(), 'types' => ItemService::TYPES];
    }
}
