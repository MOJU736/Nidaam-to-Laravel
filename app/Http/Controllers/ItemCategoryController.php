<?php

namespace App\Http\Controllers;

use App\Models\Tenant\ItemCategory;
use App\Services\Accounting\ActivityLog;
use App\Services\Inventory\ItemService;
use Illuminate\Http\Request;

/** Labels items can be tagged with, purely to keep the list organised (separate from Type, which drives the accounting). */
class ItemCategoryController extends Controller
{
    public function __construct(protected ItemService $items) {}

    public function index()
    {
        return view('items.categories', ['categories' => ItemCategory::orderBy('name')->withCount('items')->get()]);
    }

    public function store(Request $request)
    {
        $c = $this->items->saveCategory(null, (string) $request->input('name'));
        ActivityLog::add("Added Item Category: {$c->name}");

        return redirect()->route('items.categories')->with('message', "Category \"{$c->name}\" was added.");
    }

    public function update(Request $request, int $id)
    {
        $c = $this->items->saveCategory(ItemCategory::findOrFail($id), (string) $request->input('name'));
        ActivityLog::add("Renamed Item Category #{$c->id} to {$c->name}");

        return redirect()->route('items.categories')->with('message', 'Category updated.');
    }

    public function destroy(int $id)
    {
        $c = ItemCategory::findOrFail($id);
        $this->items->deleteCategory($c);
        ActivityLog::add("Deleted Item Category: {$c->name}");

        return redirect()->route('items.categories')->with('message', 'Category deleted.');
    }
}
