<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerVehicle;
use App\Models\Tenant\Item;
use App\Models\Tenant\ItemVehicleModelPrice;
use App\Services\Sales\SalesService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** What the quotation and invoice forms have in common: reading the posted lines, and the data the form needs. */
trait HandlesSalesDocuments
{
    /**
     * Posted lines[i][item_id|description|qty|unit_price|discount_type|discount_value] into clean lines. Rows with no
     * quantity are dropped. Every line must be an item from the list - no lines typed by hand.
     *
     * @return array<int, array>
     */
    protected function collectLines(Request $request, SalesService $sales): array
    {
        $lines = [];
        $typed = [];
        foreach ((array) $request->input('lines') as $row) {
            $row = (array) $row;
            $qty = str_replace(',', '', trim((string) ($row['qty'] ?? '')));
            $price = str_replace(',', '', trim((string) ($row['unit_price'] ?? '')));
            $itemId = ctype_digit((string) ($row['item_id'] ?? '')) ? (int) $row['item_id'] : null;
            $desc = trim((string) ($row['description'] ?? ''));
            if ($desc === '' && ! $itemId) {
                continue;
            }
            if ($qty === '' || ! is_numeric($qty) || (float) $qty <= 0) {
                continue;
            }
            if ($price !== '' && ! is_numeric($price)) {
                throw ValidationException::withMessages(['lines' => 'Prices must be numbers, like 25 or 1,250.50.']);
            }
            if ($itemId && $desc === '') {
                $desc = (string) Item::whereKey($itemId)->value('name');
            }
            if (! $itemId) {
                $typed[] = mb_strimwidth($desc, 0, 40, '...');
            }
            $price = $price === '' ? '0' : $price;
            $lines[] = [
                'item_id' => $itemId, 'description' => mb_substr($desc, 0, 255), 'qty' => $qty, 'unit_price' => $price, 'amount' => $sales->lineAmount($qty, $price),
                'discount_type' => $row['discount_type'] ?? null, 'discount_value' => $row['discount_value'] ?? null,
            ];
        }
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line item.']);
        }
        if ($typed) {
            throw ValidationException::withMessages(['lines' => 'Every line needs an item from your list - typed lines are not accepted. No item on: "'.implode('", "', $typed).'". Pick the item, or add it under Items first.']);
        }

        return $lines;
    }

    /** Header fields common to both forms, validated. */
    protected function validateDoc(Request $request, string $table, ?int $exceptId, SalesService $sales): array
    {
        $d = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:tenant.customers,id'], 'date' => ['required', 'date'], 'number' => ['required', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date'], 'expiry_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:500'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'tax_total' => ['nullable', 'numeric', 'min:0'],
            'doc_discount_type' => ['nullable', 'string', 'max:10'], 'doc_discount_value' => ['nullable', 'string', 'max:30'],
            'vehicle_ids' => ['nullable', 'array'],
        ]);
        if ($sales->numberTaken($table, (int) $d['number'], $exceptId)) {
            throw ValidationException::withMessages(['number' => 'Another document already has that number.']);
        }

        return [
            'customer_id' => (int) $d['customer_id'], 'date' => $d['date'], 'due_date' => $d['due_date'] ?? null, 'expiry_date' => $d['expiry_date'] ?? null,
            'number' => (int) $d['number'], 'notes' => $d['notes'] ?? null, 'tax_percent' => $d['tax_percent'] ?? null, 'tax_total' => $d['tax_total'] ?? '0.00',
            'discount_type' => $d['doc_discount_type'] ?? null, 'discount_value' => $d['doc_discount_value'] ?? null, 'vehicle_ids' => $d['vehicle_ids'] ?? [],
        ];
    }

    /** Everything the quotation / invoice form needs to draw itself. */
    protected function formData(SalesService $sales, string $kind, ?object $doc, ?int $customerId): array
    {
        $vehiclesOn = Money::vehiclesEnabled();
        $customers = Customer::where(fn ($q) => $q->where('status', 1)->when($doc, fn ($x) => $x->orWhere('id', $doc->customer_id)))->orderBy('name')->get(['id', 'name', 'address']);
        $prices = ItemVehicleModelPrice::get()->groupBy('item_id')->map(fn ($g) => $g->pluck('unit_price', 'vehicle_model_id'));
        $catalog = Item::where('status', 1)->orderBy('name')->get()->mapWithKeys(fn ($i) => [$i->id => [
            'name' => $i->name, 'description' => $i->description, 'type' => $i->type, 'price' => (string) $i->sales_price,
            'stock' => $i->type === 'inventory' ? (string) $i->quantity_on_hand : null, 'vehicle_prices' => $prices->get($i->id, collect())->all(),
        ]]);
        $vehicles = $vehiclesOn ? CustomerVehicle::orderBy('sort_order')->get()->groupBy('customer_id')->map(fn ($g) => $g->map(fn ($v) => [
            'id' => $v->id, 'label' => trim(($v->model ?? '').' '.($v->plate_number ? '- '.$v->plate_number : '')) ?: 'Vehicle', 'model_id' => $v->vehicle_model_id])->values()) : collect();

        return [
            'kind' => $kind, 'doc' => $doc, 'customers' => $customers, 'catalog' => $catalog, 'vehicles' => $vehicles, 'vehiclesOn' => $vehiclesOn,
            'selectedCustomer' => $doc->customer_id ?? $customerId, 'sales' => $sales,
            'nextNumber' => $doc ? null : $sales->nextNumber($kind === 'quotation' ? 'quotations' : 'invoices'),
        ];
    }
}
