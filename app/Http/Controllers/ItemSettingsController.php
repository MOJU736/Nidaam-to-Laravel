<?php

namespace App\Http\Controllers;

use App\Services\Accounting\ActivityLog;
use App\Services\Inventory\ItemService;
use App\Support\Tables;
use Illuminate\Http\Request;

/** Item Settings: the inventory, cost-of-goods and stock-adjustment accounts. Administrators only. */
class ItemSettingsController extends Controller
{
    public function __construct(protected ItemService $items) {}

    public function edit(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        return view('items.settings', ['settings' => $this->items->settings(), 'ledgers' => $this->items->ledgerOptions(null), 'hasAdjustment' => Tables::has('item_settings', 'adjustment_ledger_id')]);
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $ledgers = $this->items->ledgerOptions(null);
        $pick = fn ($k) => ($v = $request->input($k)) !== null && $v !== '' && isset($ledgers[$v]) ? (int) $v : null;

        $this->items->saveSettings([
            'inventory_asset_ledger_id' => $pick('inventory_asset_ledger_id'), 'cogs_ledger_id' => $pick('cogs_ledger_id'), 'adjustment_ledger_id' => $pick('adjustment_ledger_id'),
        ]);
        ActivityLog::add('Updated Item Settings');

        return redirect()->route('items.settings')->with('message', 'Item settings saved.');
    }
}
