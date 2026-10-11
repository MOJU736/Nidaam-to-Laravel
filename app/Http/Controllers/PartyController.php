<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerVehicle;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\VehicleModel;
use App\Services\Accounting\ActivityLog;
use App\Services\Parties\PartyService;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Shared CRUD + statement for customers (side 'ar') and vendors (side 'ap').
 * Subclasses only say which side they are.
 */
abstract class PartyController extends Controller
{
    abstract protected function side(): string;

    public function __construct(protected PartyService $parties) {}

    protected function cfg(): array
    {
        return $this->parties->config($this->side());
    }

    protected function find(int $id)
    {
        return $this->cfg()['party']::findOrFail($id);
    }

    public function index()
    {
        $parties = $this->parties->all($this->side());

        return view('parties.index', [
            'cfg' => $this->cfg(), 'parties' => $parties, 'stats' => $this->parties->stats($this->side(), $parties),
        ]);
    }

    public function create()
    {
        return view('parties.form', $this->formData(null));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $party = $this->parties->create($this->side(), $data);

        if ($party instanceof Customer && Money::vehiclesEnabled()) {
            $this->parties->saveVehicles($party, $this->vehicleSlots($request));
        }
        ActivityLog::add("Added {$this->cfg()['one']}: {$data['name']}");

        $to = $request->has('save_and_new') ? route("{$this->cfg()['route']}.create") : route("{$this->cfg()['route']}.index");

        return redirect($to)->with('message', "{$this->cfg()['one']} \"{$data['name']}\" was created successfully.");
    }

    public function show(Request $request, int $id)
    {
        $party = $this->find($id);
        $start = $this->date($request->query('startdate'));
        $end = $this->date($request->query('enddate'));

        return view('parties.show', [
            'cfg' => $this->cfg(), 'party' => $party, 'startdate' => $request->query('startdate'), 'enddate' => $request->query('enddate'),
            'vehicles' => $party instanceof Customer ? $this->vehicles($party) : collect(),
        ] + $this->parties->statement($this->side(), $party, $start, $end));
    }

    public function edit(int $id)
    {
        return view('parties.form', $this->formData($this->find($id)));
    }

    public function update(Request $request, int $id)
    {
        $party = $this->find($id);
        $data = $this->validated($request);
        $this->parties->update($party, $data + ['status' => $request->boolean('status')]);

        if ($party instanceof Customer && Money::vehiclesEnabled()) {
            $this->parties->saveVehicles($party, $this->vehicleSlots($request));
        }
        ActivityLog::add("Updated {$this->cfg()['one']}: {$data['name']}");

        return redirect()->route("{$this->cfg()['route']}.index")->with('message', "{$this->cfg()['one']} \"{$data['name']}\" was updated successfully.");
    }

    public function destroy(int $id)
    {
        $party = $this->find($id);
        $this->parties->delete($party);
        ActivityLog::add("Deleted {$this->cfg()['one']}: {$party->name}");

        return redirect()->route("{$this->cfg()['route']}.index")->with('message', "{$this->cfg()['one']} deleted successfully.");
    }

    protected function validated(Request $request): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'], 'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'], 'notes' => ['nullable', 'string', 'max:500'],
        ];
        if ($this->side() === 'ap') {
            $rules['expense_ledger_id'] = ['nullable', 'integer'];
        }

        return $request->validate($rules);
    }

    protected function formData($party): array
    {
        $data = ['cfg' => $this->cfg(), 'party' => $party, 'vehicles' => collect(), 'vehicleModels' => collect(), 'expenseLedgers' => collect()];

        if ($this->side() === 'ar' && Money::vehiclesEnabled()) {
            $data['vehicles'] = $party ? $this->vehicles($party) : collect();
            $data['vehicleModels'] = VehicleModel::orderBy('name')->pluck('name', 'id');
        }
        if ($this->side() === 'ap') {
            // optional default expense account: any ledger that is not a cash/bank account (type 1)
            $data['expenseLedgers'] = Ledger::where(fn ($q) => $q->where('type', '!=', 1)->orWhereNull('type'))
                ->whereNotIn('id', $this->parties->all('ap')->pluck('ledger_id'))
                ->orderBy('name')->get(['id', 'name', 'code']);
        }

        return $data;
    }

    protected function vehicles(Customer $customer)
    {
        return CustomerVehicle::where('customer_id', $customer->id)->orderBy('sort_order')->get();
    }

    protected function vehicleSlots(Request $request): array
    {
        return collect(range(1, 4))->map(fn ($i) => [
            'id' => $request->input("vehicle_row_id_$i"), 'model' => $request->input("vehicle_model_$i"),
            'plate_number' => $request->input("vehicle_plate_$i"), 'vehicle_model_id' => $request->input("vehicle_pricing_model_$i"),
        ])->all();
    }

    protected function date(?string $value): ?string
    {
        $time = $value ? strtotime($value) : false;

        return $time ? date('Y-m-d', $time) : null;
    }
}
