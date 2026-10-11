<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Item;
use App\Services\Inventory\StockService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** An item's batches, and stock adjustments. */
class StockController extends Controller
{
    public function __construct(protected StockService $stock) {}

    // ---- batches -----------------------------------------------------------

    protected function stockItem(int $id): Item
    {
        $item = Item::findOrFail($id);
        abort_unless($item->type === 'inventory' && $this->stock->batchesAvailable(), 404);

        return $item;
    }

    public function batches(int $id)
    {
        $item = $this->stockItem($id);
        $this->stock->fit($item->id);

        return view('items.batches', ['item' => $item->refresh(), 'batches' => $this->stock->batches($item->id), 'pool' => $this->stock->pool($item->id)]);
    }

    public function batchStore(Request $request, int $id)
    {
        $item = $this->stockItem($id);
        $this->stock->addBatch($item, (string) $request->input('batch_no'), $request->input('expiry_date'), (string) $request->input('qty'));

        return redirect()->route('items.batches', $item->id)->with('message', 'Batch saved.');
    }

    public function batchUpdate(Request $request, int $batchId)
    {
        $b = DB::connection('tenant')->table('item_batches')->where('id', $batchId)->first();
        abort_unless($b && $this->stock->batchesAvailable(), 404);

        if ($request->boolean('release')) {
            $this->stock->releaseBatch($batchId);
            $msg = "Batch {$b->batch_no} removed - its units now have no batch.";
        } else {
            $this->stock->editBatch($batchId, (string) $request->input('batch_no'), $request->input('expiry_date'));
            $msg = 'Batch saved.';
        }

        return redirect()->route('items.batches', $b->item_id)->with('message', $msg);
    }

    // ---- stock adjustments ---------------------------------------------------------

    protected function adjustmentsOn(): void
    {
        abort_unless($this->stock->adjustmentsAvailable(), 404, 'Stock adjustments are not set up for this company.');
    }

    public function adjustments()
    {
        $this->adjustmentsOn();

        return view('items.adjustments', ['rows' => $this->stock->adjustments(), 'reasons' => StockService::REASONS]);
    }

    public function adjustmentCreate(Request $request)
    {
        $this->adjustmentsOn();

        return view('items.adjustment-form', ['items' => $this->stock->inventoryItems(), 'reasons' => StockService::REASONS, 'preselect' => (int) $request->query('item') ?: null]);
    }

    public function adjustmentStore(Request $request)
    {
        $this->adjustmentsOn();
        $d = $request->validate(['date' => ['required', 'date'], 'reason' => ['required', 'string'], 'notes' => ['nullable', 'string', 'max:500'], 'lines' => ['required', 'array']]);
        $a = $this->stock->createAdjustment($d['date'], $d['reason'], $d['notes'] ?? null, $d['lines']);

        return redirect()->route('items.adjustments.show', $a->id)->with('message', "Stock adjustment {$a->label} posted ({$a->count} item".($a->count === 1 ? '' : 's').').');
    }

    public function adjustmentShow(int $id)
    {
        $this->adjustmentsOn();
        $adj = $this->stock->adjustment($id);
        abort_unless($adj, 404);

        return view('items.adjustment-show', ['adj' => $adj, 'reasons' => StockService::REASONS]);
    }

    public function adjustmentVoid(int $id)
    {
        $this->adjustmentsOn();
        $this->stock->voidAdjustment($id);

        return redirect()->route('items.adjustments.show', $id)->with('message', 'Adjustment voided.');
    }
}
