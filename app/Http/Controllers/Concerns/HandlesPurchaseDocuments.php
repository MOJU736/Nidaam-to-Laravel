<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Tenant\Item;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Vendor;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Inventory\ItemService;
use App\Services\Purchases\PurchasesService;
use App\Services\Sales\SalesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** What the bill and purchase-order forms share: the two kinds of lines, and the data the form needs. */
trait HandlesPurchaseDocuments
{
    /** Expense and asset accounts (never bank/cash, never a customer's or vendor's own account) a line may be posted to: id => "code - name". */
    protected function accountChoices(): array
    {
        $chart = app(ChartOfAccounts::class);
        $ids = [];
        foreach (['asset', 'expense'] as $root) {
            if ($g = $chart->topLevelGroupId($root)) {
                $ids = array_merge($ids, $chart->descendantLedgerIds($g));
            }
        }
        $party = array_merge(DB::connection('tenant')->table('customers')->pluck('ledger_id')->all(), DB::connection('tenant')->table('vendors')->pluck('ledger_id')->all());

        return Ledger::whereIn('id', $ids)->where(fn ($q) => $q->where('type', '!=', 1)->orWhereNull('type'))->whereNotIn('id', $party)->orderBy('code')
            ->get(['id', 'name', 'code'])->mapWithKeys(fn ($l) => [$l->id => trim(($l->code ? $l->code.' - ' : '').$l->name)])->all();
    }

    /**
     * "Item details" lines (items[i][item_id|description|qty|unit_price|ledger_id|discount_*]) and "Account details" lines
     * (accounts[i][ledger_id|description|amount]) merged for saving. An item line needs an item from the list - typed lines are
     * refused; a cost with no item (rent) goes in Account details.
     */
    protected function collectBillLines(Request $request, SalesService $sales, bool $discounts = true): array
    {
        $lines = [];
        $typed = [];
        $valid = $this->accountChoices();
        $inv = app(ItemService::class)->settings()->inventory_asset_ledger_id;

        foreach ((array) $request->input('items') as $row) {
            $row = (array) $row;
            $qty = str_replace(',', '', trim((string) ($row['qty'] ?? '')));
            $price = str_replace(',', '', trim((string) ($row['unit_price'] ?? '')));
            $itemId = ctype_digit((string) ($row['item_id'] ?? '')) ? (int) $row['item_id'] : null;
            $desc = trim((string) ($row['description'] ?? ''));
            if ($qty === '' || ! is_numeric($qty) || (float) $qty <= 0 || ($desc === '' && ! $itemId)) {
                continue;
            }
            if ($price !== '' && ! is_numeric($price)) {
                throw ValidationException::withMessages(['lines' => 'Prices must be numbers, like 25 or 1,250.50.']);
            }
            if (! $itemId) {
                $typed[] = mb_strimwidth($desc, 0, 40, '...');

                continue;
            }
            $item = Item::find($itemId);
            if (! $item) {
                continue;
            }
            $ledgerId = (int) ($row['ledger_id'] ?? 0);
            if ($item->type === 'inventory') {
                // stock is posted to Inventory Asset when the bill is issued; a company set up without stock gets that account on first use
                if (! $inv) {
                    app(ItemService::class)->ensureInventoryAccounts();
                    $inv = app(ItemService::class)->settings()->inventory_asset_ledger_id;
                }
                $ledgerId = (int) ($inv ?: ($item->expense_ledger_id ?: $ledgerId));
            }
            if (! $ledgerId || ($item->type !== 'inventory' && ! isset($valid[$ledgerId]))) {
                throw ValidationException::withMessages(['lines' => "Choose the account for \"{$item->name}\"."]);
            }
            $price = $price === '' ? '0' : $price;
            $lines[] = ['item_id' => $itemId, 'ledger_id' => $ledgerId, 'description' => mb_substr($desc ?: $item->name, 0, 255), 'qty' => $qty, 'unit_price' => $price,
                'amount' => $sales->lineAmount($qty, $price), 'discount_type' => $discounts ? ($row['discount_type'] ?? null) : null, 'discount_value' => $discounts ? ($row['discount_value'] ?? null) : null];
        }
        if ($typed) {
            throw ValidationException::withMessages(['lines' => 'Every Item details line needs an item from your list - typed lines are not accepted. No item on: "'.implode('", "', $typed).'". Pick the item, or add it under Items first. (For a cost with no item, like rent, use Account details.)']);
        }

        foreach ((array) $request->input('accounts') as $row) {
            $row = (array) $row;
            $amount = str_replace(',', '', trim((string) ($row['amount'] ?? '')));
            $ledgerId = (int) ($row['ledger_id'] ?? 0);
            $desc = trim((string) ($row['description'] ?? ''));
            if (! $ledgerId || $desc === '' || ! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }
            if (! isset($valid[$ledgerId])) {
                throw ValidationException::withMessages(['lines' => 'One of the accounts on the Account details lines is not valid.']);
            }
            $lines[] = ['item_id' => null, 'ledger_id' => $ledgerId, 'description' => mb_substr($desc, 0, 255), 'qty' => '1', 'unit_price' => $amount, 'amount' => $sales->lineAmount(1, $amount),
                'discount_type' => null, 'discount_value' => null];
        }
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'Add at least one line item.']);
        }

        return $lines;
    }

    protected function validateBillDoc(Request $request, string $table, ?int $exceptId, SalesService $sales): array
    {
        $d = $request->validate([
            'vendor_id' => ['required', 'integer', 'exists:tenant.vendors,id'], 'date' => ['required', 'date'], 'number' => ['required', 'integer', 'min:1'], 'due_date' => ['nullable', 'date'],
            'vendor_ref' => ['nullable', 'string', 'max:100'], 'method' => ['nullable', 'string', 'max:50'], 'notes' => ['nullable', 'string', 'max:500'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'], 'tax_total' => ['nullable', 'numeric', 'min:0'], 'doc_discount_type' => ['nullable', 'string', 'max:10'], 'doc_discount_value' => ['nullable', 'string', 'max:30'],
        ]);
        if ($sales->numberTaken($table, (int) $d['number'], $exceptId)) {
            throw ValidationException::withMessages(['number' => 'Another document already has that number.']);
        }

        return ['vendor_id' => (int) $d['vendor_id'], 'date' => $d['date'], 'due_date' => $d['due_date'] ?? null, 'number' => (int) $d['number'], 'vendor_ref' => $d['vendor_ref'] ?? null,
            'method' => $d['method'] ?? null, 'notes' => $d['notes'] ?? null, 'tax_percent' => $d['tax_percent'] ?? null, 'tax_total' => $d['tax_total'] ?? '0.00',
            'discount_type' => $d['doc_discount_type'] ?? null, 'discount_value' => $d['doc_discount_value'] ?? null];
    }

    protected function billFormData(PurchasesService $purchases, SalesService $sales, string $kind, ?object $doc, ?int $vendorId): array
    {
        $inv = app(ItemService::class)->settings()->inventory_asset_ledger_id;
        $catalog = Item::where('status', 1)->orderBy('name')->get()->mapWithKeys(fn ($i) => [$i->id => [
            'name' => $i->name, 'description' => $i->description, 'type' => $i->type, 'cost' => (string) $i->purchase_cost,
            'ledger_id' => $i->type === 'inventory' ? $inv : $i->expense_ledger_id,
        ]]);

        return [
            'kind' => $kind, 'doc' => $doc, 'purchases' => $purchases, 'catalog' => $catalog, 'accounts' => $this->accountChoices(),
            'vendors' => Vendor::where(fn ($q) => $q->where('status', 1)->when($doc, fn ($x) => $x->orWhere('id', $doc->vendor_id)))->orderBy('name')->get(['id', 'name']),
            'selectedVendor' => $doc->vendor_id ?? $vendorId, 'nextNumber' => $doc ? null : $purchases->nextNumber($kind === 'bill' ? 'bills' : 'purchase_orders'),
            'methods' => DB::connection('tenant')->table('payment_methods')->orderBy('name')->pluck('name')->all(), 'inventoryLedger' => $inv,
        ];
    }
}
