<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Item;
use App\Models\Tenant\VehicleModel;
use App\Services\Accounting\ActivityLog;
use App\Services\Inventory\ItemService;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Vehicle models (Prado, Hilux ...) and the special price each item can carry for them, on top of its normal
 * sales price. Customers' vehicles are optionally tagged with a model so the invoice item picker knows which
 * price to use. Only for companies with the Vehicles feature on; the catalog itself is administrators only.
 */
class VehicleModelController extends Controller
{
    public function __construct(protected ItemService $items) {}

    protected function gate(Request $request, bool $admin = true): void
    {
        abort_unless(Money::vehiclesEnabled(), 403, 'The Vehicles feature is turned off for this company. Enable it under Account Settings to use Vehicle Pricing.');
        $admin && abort_unless($request->user()->isAdmin(), 403);
    }

    public function index(Request $request)
    {
        $this->gate($request);

        return view('items.vehicle-models', ['models' => VehicleModel::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $this->gate($request);
        $m = $this->items->saveVehicleModel(null, (string) $request->input('name'));
        ActivityLog::add("Added Vehicle Model: {$m->name}");

        return redirect()->route('items.vehicles')->with('message', "Vehicle model \"{$m->name}\" was added.");
    }

    public function update(Request $request, int $id)
    {
        $this->gate($request);
        $m = $this->items->saveVehicleModel(VehicleModel::findOrFail($id), (string) $request->input('name'));
        ActivityLog::add("Renamed Vehicle Model #{$m->id} to {$m->name}");

        return redirect()->route('items.vehicles')->with('message', 'Vehicle model updated.');
    }

    public function destroy(Request $request, int $id)
    {
        $this->gate($request);
        $m = VehicleModel::findOrFail($id);
        $this->items->deleteVehicleModel($m);
        ActivityLog::add("Deleted Vehicle Model: {$m->name}");

        return redirect()->route('items.vehicles')->with('message', 'Vehicle model deleted.');
    }

    public function prices(Request $request, int $id)
    {
        $this->gate($request, false);

        return view('items.vehicle-prices', ['model' => VehicleModel::findOrFail($id), 'items' => Item::orderBy('name')->get(), 'prices' => $this->items->vehiclePrices($id)]);
    }

    public function savePrices(Request $request, int $id)
    {
        $this->gate($request, false);
        $m = VehicleModel::findOrFail($id);
        $this->items->saveVehiclePrices($m, (array) $request->input('price'));
        ActivityLog::add("Updated item prices for Vehicle Model: {$m->name}");

        return redirect()->route('items.vehicles.prices', $id)->with('message', "Prices for \"{$m->name}\" were saved.");
    }
}
