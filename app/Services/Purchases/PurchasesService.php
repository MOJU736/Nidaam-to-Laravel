<?php

namespace App\Services\Purchases;

use App\Models\Tenant\Ledger;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Services\Inventory\ItemService;
use App\Services\Sales\Allocations;
use App\Services\Sales\SalesService;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchases: bills (which post to the books when issued), purchase orders, vendor payments and quick expenses.
 * Ported from the legacy Purchases_model - the supplier-side mirror of SalesService.
 *
 * Issuing a bill posts one journal entry:
 *   Dr each line's expense or asset account (full line amount; stock lines always go to Inventory Asset), Dr tax
 *   Cr the vendor's own account (the bill total), Cr Discounts received (all discounts).
 * Stock bought raises quantity on hand and moves the item's weighted-average cost.
 */
class PurchasesService
{
    public function __construct(
        protected Allocations $allocs,
        protected ChartOfAccounts $chart,
        protected EntryService $entries,
        protected ItemService $items,
        protected SalesService $sales,
    ) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    // ---- settings ----------------------------------------------------------------

    public function settings(): object
    {
        return $this->db()->table('purchase_settings')->where('id', 1)->first()
            ?? (object) ['ap_group_id' => null, 'tax_ledger_id' => null, 'entrytype_id' => null, 'bill_prefix' => 'BILL-', 'discount_ledger_id' => null];
    }

    public function saveSettings(array $d): void
    {
        $cols = array_filter($d, fn ($v, $k) => Tables::has('purchase_settings', $k), ARRAY_FILTER_USE_BOTH);
        $q = $this->db()->table('purchase_settings');
        $q->where('id', 1)->exists() ? $q->where('id', 1)->update($cols) : $q->insert(['id' => 1] + $cols);
    }

    public function billLabel($number): string
    {
        return ($this->settings()->bill_prefix ?: 'BILL-').str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public function poLabel($number): string
    {
        return 'PO-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public function nextNumber(string $table): int
    {
        return $this->sales->nextNumber($table);
    }

    /** Where discounts from suppliers are credited: the saved account, else "Discounts taken/received" in the chart, else a new income account. */
    public function discountLedger(): ?int
    {
        $s = $this->settings();
        if ($s->discount_ledger_id && Ledger::where('id', $s->discount_ledger_id)->exists()) {
            return (int) $s->discount_ledger_id;
        }
        $l = Ledger::where('code', '04-02-01-0003')->first();
        if ($l) {
            $l->update(['is_contra' => 1]); // under Expenses but carries a credit balance
        } else {
            $l = Ledger::where('name', 'like', '%Discounts taken%')->first() ?? Ledger::where('name', 'like', '%Discounts received%')->first();
        }
        if (! $l) {
            $income = $this->chart->topLevelGroupId('income');
            if (! $income) {
                return null;
            }
            $l = Ledger::create(['group_id' => $income, 'name' => 'Discounts Received', 'code' => $this->chart->nextLedgerCode($income), 'op_balance' => '0.00', 'op_balance_dc' => 'C',
                'type' => 0, 'reconciliation' => 0, 'notes' => 'Discounts received from suppliers on bills (created automatically)']);
            ActivityLog::add('Created ledger "Discounts Received" for bill discounts');
        }
        $this->saveSettings(['discount_ledger_id' => $l->id]);

        return (int) $l->id;
    }

    // ---- bills ---------------------------------------------------------------------

    public function bill(int $id): ?object
    {
        $b = $this->db()->table('bills')->join('vendors', 'vendors.id', '=', 'bills.vendor_id')->where('bills.id', $id)
            ->first(['bills.*', 'vendors.name as vendor_name', 'vendors.address as vendor_address', 'vendors.email as vendor_email', 'vendors.phone as vendor_phone', 'vendors.ledger_id as vendor_ledger_id']);
        if ($b) {
            $b->items = $this->db()->table('bill_items')->join('ledgers', 'ledgers.id', '=', 'bill_items.ledger_id')->where('bill_id', $id)->orderBy('sort_order')
                ->get(['bill_items.*', 'ledgers.name as ledger_name', 'ledgers.code as ledger_code']);
        }

        return $b;
    }

    /**
     * Save a bill (or purchase order, $table 'purchase_orders'). Lines carry item_id (or null for an account line),
     * ledger_id, description, qty, unit_price, amount and optional line discounts.
     */
    public function saveBill(array $d, array $lines, ?int $id = null, string $table = 'bills'): int
    {
        $itemTable = $table === 'bills' ? 'bill_items' : 'purchase_order_items';
        $fk = $table === 'bills' ? 'bill_id' : 'purchase_order_id';

        return $this->db()->transaction(function () use ($d, $lines, $id, $table, $itemTable, $fk) {
            // purchase orders carry no discounts
            $t = $this->sales->documentTotals($lines, $table === 'bills' ? ($d['discount_type'] ?? null) : null, $table === 'bills' ? ($d['discount_value'] ?? null) : null);
            [$tax, $pct] = $this->sales->tax($t['taxable'], $d);
            $row = [
                'vendor_id' => $d['vendor_id'], 'date' => $d['date'], 'due_date' => $d['due_date'] ?? null, 'notes' => $d['notes'] ?? null, 'subtotal' => $t['subtotal'],
                'tax_total' => $tax, 'tax_percent' => $pct, 'total' => Money::add($t['taxable'], $tax),
            ];
            if ($table === 'bills') {
                $row += ['vendor_ref' => $d['vendor_ref'] ?? null, 'method' => $d['method'] ?? null, 'discount_type' => $t['discount_type'], 'discount_value' => $t['discount_value'],
                    'discount_amount' => $t['discount_amount'], 'discount_total' => $t['discount_total']];
                if (array_key_exists('purchase_order_id', $d)) {
                    $row['purchase_order_id'] = $d['purchase_order_id'];
                }
            }
            if (! empty($d['number'])) {
                $row['number'] = (int) $d['number'];
            }

            if ($id) {
                $this->db()->table($table)->where('id', $id)->update($row);
                $this->db()->table($itemTable)->where($fk, $id)->delete();
            } else {
                $row += ['number' => $this->nextNumber($table), 'status' => 'draft', 'created_at' => now()];
                $id = $this->db()->table($table)->insertGetId($row);
            }
            foreach (array_values($t['items']) as $i => $l) {
                $this->db()->table($itemTable)->insert([$fk => $id, 'item_id' => $l['item_id'] ?: null, 'ledger_id' => $l['ledger_id'], 'description' => $l['description'], 'qty' => $l['qty'],
                    'unit_price' => $l['unit_price'], 'amount' => $l['amount'], 'sort_order' => $i]
                    + ($table === 'bills' ? ['discount_type' => $l['discount_type'], 'discount_value' => $l['discount_value'], 'discount_amount' => $l['discount_amount']] : []));
            }

            return $id;
        });
    }

    protected function guardBooks(?string $date): void
    {
        if ($this->chart->companyLocked()) {
            $this->fail('This account is locked. Unlock it under Account Settings first.');
        }
        if ($err = $this->entries->closedPeriodError($date ? substr($date, 0, 10) : null)) {
            $this->fail($err);
        }
    }

    protected function lockStatus(string $table, int $id): ?string
    {
        return $this->db()->selectOne("SELECT status FROM `$table` WHERE id = ? FOR UPDATE", [$id])?->status;
    }

    public function issueBill(int $id): void
    {
        if ($this->chart->companyLocked()) {
            $this->fail('This account is locked. Unlock it under Account Settings before issuing bills.');
        }
        $b = $this->bill($id) ?? $this->fail('Bill not found.');
        if ($b->status !== 'draft') {
            $this->fail('Only draft bills can be issued.');
        }
        if ($b->items->isEmpty()) {
            $this->fail('Add at least one line item before issuing.');
        }
        $this->guardBooks($b->date);
        $s = $this->settings();
        if (! $s->ap_group_id || ! $s->entrytype_id) {
            $this->fail('Accounts Payable group is not configured. Set it under Purchases → Settings first.');
        }
        if (Money::positive($b->tax_total) && ! $s->tax_ledger_id) {
            $this->fail('This bill has tax but no Tax account is configured under Purchases → Settings.');
        }

        // stock lines always go to Inventory Asset (buying stock is an asset), and the item's quantity and weighted-average cost move
        $postLedger = [];
        $stock = [];
        $itemSettings = null;
        foreach ($b->items as $l) {
            $item = $l->item_id ? $this->db()->table('items')->where('id', $l->item_id)->first() : null;
            if (! $item || $item->type !== 'inventory') {
                continue;
            }
            if ($itemSettings === null) {
                $this->items->ensureInventoryAccounts();
                $itemSettings = $this->items->settings();
                if (! $itemSettings->inventory_asset_ledger_id) {
                    $this->fail('This bill buys an Inventory item, but the Inventory Asset account is not configured. Set it under Items → Settings first.');
                }
            }
            $postLedger[$l->id] = $itemSettings->inventory_asset_ledger_id;
            $oldQty = $stock[$item->id]['qty'] ?? $item->quantity_on_hand;
            $oldCost = $stock[$item->id]['cost'] ?? $item->purchase_cost;
            $newQty = Money::add($oldQty, $l->qty);
            // if stock was at or below zero before and after, an average is meaningless: the cost becomes this purchase's own price
            $newCost = Money::positive($newQty)
                ? Money::round(bcdiv(bcadd(bcmul((string) $oldQty, (string) $oldCost, 8), bcmul((string) $l->qty, (string) $l->unit_price, 8), 8), (string) $newQty, 8))
                : Money::add($l->unit_price, 0);
            $stock[$item->id] = ['qty' => $newQty, 'cost' => $newCost];
        }

        $discount = $b->discount_total ?? '0.00';
        $discountLedger = null;
        if (Money::positive($discount) && ! ($discountLedger = $this->discountLedger())) {
            $this->fail('This bill has a discount but no Discounts account could be found or created. Pick one under Purchases → Settings first.');
        }

        // never post an entry that doesn't balance
        $dr = $b->tax_total;
        foreach ($b->items as $l) {
            $dr = Money::add($dr, $l->amount);
        }
        $cr = Money::add($b->total, $discount);
        if (Money::cmp($dr, $cr) !== 0) {
            $this->fail("This bill's totals don't add up (debits $dr, credits $cr), so it was not posted. Open it, press Save once to recalculate, then issue again.");
        }

        $this->db()->transaction(function () use ($id, $b, $s, $postLedger, $stock, $discount, $discountLedger, $dr, $cr) {
            if ($this->lockStatus('bills', $id) !== 'draft') {
                $this->fail('Only draft bills can be issued.');
            }
            $note = "Purchase Bill #{$b->number}";
            $entryId = $this->db()->table('entries')->insertGetId(['entrytype_id' => $s->entrytype_id, 'tag_id' => null, 'number' => $this->entries->nextNumber($s->entrytype_id),
                'date' => substr($b->date, 0, 10), 'dr_total' => $dr, 'cr_total' => $cr, 'notes' => "$note - {$b->vendor_name}"]);
            $lines = [];
            foreach ($b->items as $l) {
                $ledger = $postLedger[$l->id] ?? $l->ledger_id;
                $lines[] = ['ledger_id' => $ledger, 'amount' => $l->amount, 'dc' => 'D', 'narration' => "$note - {$l->description}"];
                if ((int) $ledger !== (int) $l->ledger_id) {
                    $this->db()->table('bill_items')->where('id', $l->id)->update(['ledger_id' => $ledger]);
                }
            }
            if (Money::positive($b->tax_total)) {
                $lines[] = ['ledger_id' => $s->tax_ledger_id, 'amount' => $b->tax_total, 'dc' => 'D', 'narration' => "$note - Tax"];
            }
            $lines[] = ['ledger_id' => $b->vendor_ledger_id, 'amount' => $b->total, 'dc' => 'C', 'narration' => $note];
            if ($discountLedger) {
                $lines[] = ['ledger_id' => $discountLedger, 'amount' => $discount, 'dc' => 'C', 'narration' => "$note - Discount"];
            }
            $this->db()->table('entryitems')->insert(array_map(fn ($l) => $l + ['entry_id' => $entryId], $lines));

            foreach ($stock as $itemId => $u) {
                $this->db()->table('items')->where('id', $itemId)->update(['quantity_on_hand' => $u['qty'], 'purchase_cost' => $u['cost']]);
            }
            $this->db()->table('bills')->where('id', $id)->update(['entry_id' => $entryId, 'status' => 'issued']);
        });
        ActivityLog::add("Issued Purchase Bill #{$b->number} from {$b->vendor_name}");
    }

    /** Remove the bill's entry and take the stock it bought back out (refused if some of it was already sold). */
    protected function unpost(object $b, string $newStatus, string $result): void
    {
        $this->allocs->releaseDoc('ap', $b->id); // vendor payments applied to it go back to being a prepayment
        $needs = [];
        foreach ($b->items as $l) {
            if ($l->item_id) {
                $needs[$l->item_id] = Money::add($needs[$l->item_id] ?? 0, $l->qty);
            }
        }
        if ($needs && ($short = $this->sales->stockShortfall($needs, $result, 'Some of these items were already sold. Fix it with a stock adjustment or a return to the supplier instead.'))) {
            $this->fail($short);
        }
        $entryId = $b->entry_id;
        $this->db()->table('bills')->where('id', $b->id)->update(['entry_id' => null, 'status' => $newStatus]);
        if ($entryId) {
            $this->db()->table('entryitems')->where('entry_id', $entryId)->delete();
            $this->db()->table('entries')->where('id', $entryId)->delete();
        }
        foreach ($b->items as $l) {
            if ($l->item_id) {
                // quantity is exactly reversible; the average cost is left as it is (reversing it precisely is ambiguous under weighted-average costing)
                $this->db()->update("UPDATE items SET quantity_on_hand = quantity_on_hand - ? WHERE id = ? AND type = 'inventory'", [$l->qty, $l->item_id]);
            }
        }
    }

    public function voidBill(int $id): void
    {
        $b = $this->bill($id) ?? $this->fail('Bill not found.');
        $this->guardBooks($b->date);
        if ($b->status !== 'issued') {
            $this->fail('Only issued bills can be voided.');
        }
        $this->db()->transaction(function () use ($id, $b) {
            if ($this->lockStatus('bills', $id) !== 'issued') {
                $this->fail('Only issued bills can be voided.');
            }
            $this->unpost($b, 'void', 'The bill was not voided.');
        });
        ActivityLog::add("Voided Purchase Bill #{$b->number} from {$b->vendor_name}");
    }

    public function revertBill(int $id): void
    {
        $b = $this->bill($id) ?? $this->fail('Bill not found.');
        $this->guardBooks($b->date);
        if ($b->status !== 'issued') {
            $this->fail('Only issued bills can be reverted to draft.');
        }
        $this->db()->transaction(function () use ($id, $b) {
            if ($this->lockStatus('bills', $id) !== 'issued') {
                $this->fail('Only issued bills can be reverted to draft.');
            }
            $this->unpost($b, 'draft', 'The bill was not reverted to draft.');
        });
        ActivityLog::add("Reverted Purchase Bill #{$b->number} from {$b->vendor_name} back to draft");
    }

    /** A draft never posted anything, and a voided bill was already reversed: both are safe to delete. */
    public function deleteBill(int $id): void
    {
        $b = $this->bill($id) ?? $this->fail('Bill not found.');
        if (! in_array($b->status, ['draft', 'void'], true)) {
            $this->fail('Only draft or voided bills can be deleted. Void this bill first.');
        }
        $this->db()->transaction(function () use ($id, $b) {
            $this->allocs->releaseDoc('ap', $id);
            $this->db()->table('bill_items')->where('bill_id', $id)->delete();
            $this->db()->table('bills')->where('id', $id)->delete();
            // a purchase order it came from is back to draft so it can be converted again
            if ($b->purchase_order_id) {
                $this->db()->table('purchase_orders')->where('id', $b->purchase_order_id)->update(['status' => 'draft']);
            }
        });
        ActivityLog::add("Deleted Bill #{$b->number}");
    }

    // ---- purchase orders (a draft that never posts; it converts into a bill) -------------------------

    public function purchaseOrder(int $id): ?object
    {
        $p = $this->db()->table('purchase_orders')->join('vendors', 'vendors.id', '=', 'purchase_orders.vendor_id')->where('purchase_orders.id', $id)
            ->first(['purchase_orders.*', 'vendors.name as vendor_name', 'vendors.address as vendor_address']);
        if ($p) {
            $p->items = $this->db()->table('purchase_order_items')->join('ledgers', 'ledgers.id', '=', 'purchase_order_items.ledger_id')->where('purchase_order_id', $id)->orderBy('sort_order')
                ->get(['purchase_order_items.*', 'ledgers.name as ledger_name']);
        }

        return $p;
    }

    public function deletePurchaseOrder(int $id): void
    {
        $p = $this->purchaseOrder($id) ?? $this->fail('Purchase order not found.');
        if ($p->status === 'converted') {
            $this->fail('This purchase order has already been converted to a bill and cannot be deleted.');
        }
        $this->db()->transaction(function () use ($id) {
            $this->db()->table('purchase_order_items')->where('purchase_order_id', $id)->delete();
            $this->db()->table('purchase_orders')->where('id', $id)->delete();
        });
    }

    /** A purchase order becomes a draft bill with the same lines and tax; the order is marked converted. */
    public function convertPurchaseOrder(int $id): int
    {
        return $this->db()->transaction(function () use ($id) {
            $p = $this->purchaseOrder($id) ?? $this->fail('Purchase order not found.');
            if ($p->status === 'converted' || $this->lockStatus('purchase_orders', $id) === 'converted') {
                $this->fail('This purchase order was already converted to a bill.');
            }
            $lines = $p->items->map(fn ($i) => ['item_id' => $i->item_id, 'ledger_id' => $i->ledger_id, 'description' => $i->description, 'qty' => $i->qty, 'unit_price' => $i->unit_price, 'amount' => $i->amount])->all();
            $billId = $this->saveBill(['vendor_id' => $p->vendor_id, 'purchase_order_id' => $id, 'date' => now()->toDateString(), 'notes' => $p->notes, 'tax_total' => $p->tax_total, 'tax_percent' => $p->tax_percent], $lines);
            $this->db()->table('purchase_orders')->where('id', $id)->update(['status' => 'converted']);
            ActivityLog::add("Converted Purchase Order #{$p->number} to Bill #$billId for {$p->vendor_name}");

            return $billId;
        });
    }

    // ---- vendor payments ---------------------------------------------------------------------------------

    public function payment(int $id): ?object
    {
        $p = $this->db()->table('vendor_payments')->join('vendors', 'vendors.id', '=', 'vendor_payments.vendor_id')->join('ledgers', 'ledgers.id', '=', 'vendor_payments.ledger_id')
            ->where('vendor_payments.id', $id)->first(['vendor_payments.*', 'vendors.name as vendor_name', 'vendors.ledger_id as vendor_ledger_id', 'ledgers.name as ledger_name']);
        if ($p) {
            $p->posted_ledger_name = ! empty($p->posted_ledger_id) && (int) $p->posted_ledger_id !== (int) $p->vendor_ledger_id ? $this->db()->table('ledgers')->where('id', $p->posted_ledger_id)->value('name') : null;
        }

        return $p;
    }

    /**
     * Pay a vendor: Dr the vendor's account, Cr the bank/cash account. A vendor with a default expense account is paid
     * straight to it instead (skipping Accounts Payable) - unless the payment is applied to bills, which always go to AP.
     */
    public function payVendor(array $d): int
    {
        $this->guardBooks($d['date'] ?? null);
        $vendor = $this->db()->table('vendors')->where('id', $d['vendor_id'])->first() ?? $this->fail('Vendor not found.');
        $bank = Ledger::find($d['ledger_id']);
        if (! $bank || (int) $bank->type !== 1) {
            $this->fail('Select a valid bank or cash account to pay from.');
        }
        $amount = Money::round(str_replace(',', '', (string) $d['amount']));
        if (! Money::positive($amount)) {
            $this->fail('Enter a payment amount greater than zero.');
        }
        $type = $this->entries->typeByLabel('payment');
        $number = $this->sales->resolvePaymentNumberFor('vendor_payments', $d['number'] ?? null);
        $label = Allocations::docLabel('', $number);

        $apply = [];
        foreach ((array) ($d['apply'] ?? []) as $billId => $amt) {
            $amt = str_replace(',', '', trim((string) $amt));
            if ($amt !== '' && is_numeric($amt) && Money::positive($amt)) {
                $apply[(int) $billId] = $amt;
            }
        }
        $posting = ! empty($vendor->expense_ledger_id) ? $vendor->expense_ledger_id : $vendor->ledger_id;
        if ($apply) {
            $posting = $vendor->ledger_id; // paying bills: it has to go to Accounts Payable, where the bill put the amount owed
        }

        return $this->db()->transaction(function () use ($d, $vendor, $amount, $type, $number, $label, $apply, $posting) {
            $entryId = $this->db()->table('entries')->insertGetId(['entrytype_id' => $type->id, 'tag_id' => null, 'number' => $this->entries->nextNumber($type->id),
                'date' => $d['date'], 'dr_total' => $amount, 'cr_total' => $amount, 'notes' => "Payment $label to {$vendor->name}"]);
            $this->db()->table('entryitems')->insert([
                ['entry_id' => $entryId, 'ledger_id' => $posting, 'amount' => $amount, 'dc' => 'D', 'narration' => "Payment $label to {$vendor->name}"],
                ['entry_id' => $entryId, 'ledger_id' => $d['ledger_id'], 'amount' => $amount, 'dc' => 'C', 'narration' => "Payment $label"],
            ]);
            $id = $this->db()->table('vendor_payments')->insertGetId([
                'vendor_id' => $vendor->id, 'ledger_id' => $d['ledger_id'], 'entry_id' => $entryId, 'posted_ledger_id' => $posting, 'number' => $number, 'date' => $d['date'], 'amount' => $amount,
                'method' => $d['method'] ?? null, 'reference' => $d['reference'] ?? null, 'notes' => $d['notes'] ?? null, 'status' => 'paid', 'created_at' => now(),
            ]);
            if ($apply) {
                $this->allocs->allocate('ap', $id, $apply, 'payment');
            }
            ActivityLog::add("Paid $label to {$vendor->name}");

            return $id;
        });
    }

    public function paymentUsed(int $id): string
    {
        return Money::add($this->allocs->appliedBySource('ap', [$id])[$id] ?? 0, $this->allocs->refundedBySource('ap', [$id], 'pay')[$id] ?? 0);
    }

    /**
     * Edit a paid vendor payment in place: date, paid-from account, amount, number, method, reference, notes. The vendor and the
     * account it was posted to stay the same; the journal entry keeps its id and number. The bills it pays stay paid, so the amount
     * can't go below what is applied and refunded.
     */
    public function updatePayment(int $id, array $d): array
    {
        $this->guardBooks($d['date'] ?? null);
        $p = $this->payment($id) ?? $this->fail('Payment not found.');
        if ($p->status !== 'paid' || ! $p->entry_id) {
            $this->fail('Only a paid payment can be edited.');
        }
        if ($err = $this->entries->closedPeriodError(substr($p->date, 0, 10))) {
            $this->fail($err);
        }
        $bank = Ledger::find($d['ledger_id']);
        if (! $bank || (int) $bank->type !== 1) {
            $this->fail('Select a valid bank or cash account to pay from.');
        }
        $amount = Money::round(str_replace(',', '', trim((string) $d['amount'])));
        if (! is_numeric($amount) || ! Money::positive($amount)) {
            $this->fail('Enter a payment amount greater than zero.');
        }
        $used = $this->paymentUsed($id);
        if (Money::cmp($amount, $used) < 0) {
            $this->fail(Money::format($used)." of this payment is already applied to bills or refunded, so the amount can't be less than that. Remove it from a bill first (Remove on the payment page), then edit.");
        }
        $number = trim((string) ($d['number'] ?? '')) !== '' && (string) $d['number'] !== (string) $p->number ? $this->sales->resolvePaymentNumberFor('vendor_payments', $d['number'], $id) : (int) $p->number;
        $label = Allocations::docLabel('', $number);
        $posting = ! empty($p->posted_ledger_id) ? (int) $p->posted_ledger_id : (int) $p->vendor_ledger_id;
        $fields = ['ledger_id' => (int) $d['ledger_id'], 'number' => $number, 'date' => $d['date'], 'amount' => $amount, 'method' => (string) ($d['method'] ?? ''), 'reference' => (string) ($d['reference'] ?? ''), 'notes' => (string) ($d['notes'] ?? '')];

        $changes = [];
        if (substr($p->date, 0, 10) !== $fields['date']) {
            $changes[] = 'date '.Money::date($p->date).' to '.Money::date($fields['date']);
        }
        if ((int) $p->ledger_id !== $fields['ledger_id']) {
            $changes[] = "paid from {$p->ledger_name} to {$bank->name}";
        }
        if (Money::cmp($p->amount, $amount) !== 0) {
            $changes[] = 'amount '.Money::format($p->amount).' to '.Money::format($amount);
        }
        if ((int) $p->number !== $number) {
            $changes[] = 'number '.Allocations::docLabel('', $p->number)." to $label";
        }
        foreach (['method', 'reference', 'notes'] as $f) {
            if ((string) $p->{$f} !== $fields[$f]) {
                $changes[] = $f;
            }
        }
        if (! $changes) {
            return [];
        }

        $this->db()->transaction(function () use ($p, $id, $fields, $amount, $label, $posting) {
            $this->db()->table('entries')->where('id', $p->entry_id)->update(['date' => $fields['date'], 'dr_total' => $amount, 'cr_total' => $amount, 'notes' => "Payment $label to {$p->vendor_name}"]);
            $this->db()->table('entryitems')->where('entry_id', $p->entry_id)->delete();
            $this->db()->table('entryitems')->insert([
                ['entry_id' => $p->entry_id, 'ledger_id' => $posting, 'amount' => $amount, 'dc' => 'D', 'narration' => "Payment $label to {$p->vendor_name}"],
                ['entry_id' => $p->entry_id, 'ledger_id' => $fields['ledger_id'], 'amount' => $amount, 'dc' => 'C', 'narration' => "Payment $label"],
            ]);
            $this->db()->table('vendor_payments')->where('id', $id)->update($fields);
        });
        ActivityLog::add("Edited payment $label to {$p->vendor_name}: ".implode(', ', $changes));

        return $changes;
    }

    public function voidPayment(int $id): void
    {
        $p = $this->payment($id) ?? $this->fail('Payment not found.');
        $this->guardBooks($p->date);
        if ($p->status !== 'paid') {
            $this->fail('Only paid payments can be voided.');
        }
        if ($this->allocs->hasRefunds('ap', 'pay', $id)) {
            $this->fail('Part of this payment was refunded. Delete the refund first (on the payment page).');
        }
        $this->db()->transaction(function () use ($id, $p) {
            $this->allocs->releaseSource('ap', $id); // the bills it paid become unpaid again
            $this->db()->table('vendor_payments')->where('id', $id)->update(['entry_id' => null, 'status' => 'void']);
            if ($p->entry_id) {
                $this->db()->table('entryitems')->where('entry_id', $p->entry_id)->delete();
                $this->db()->table('entries')->where('id', $p->entry_id)->delete();
            }
        });
        ActivityLog::add('Voided payment '.Allocations::docLabel('', $p->number)." to {$p->vendor_name}");
    }

    /** Only a voided payment can be deleted for good. */
    public function deletePayment(int $id): void
    {
        $p = $this->payment($id) ?? $this->fail('Payment not found.');
        if ($this->chart->companyLocked()) {
            $this->fail('This account is locked. Unlock it under Account Settings first.');
        }
        if ($p->status !== 'void') {
            $this->fail('Void this payment first. Only a voided payment can be deleted.');
        }
        if ($this->allocs->hasRefunds('ap', 'pay', $id)) {
            $this->fail('Part of this payment was refunded. Delete the refund first (on the payment page).');
        }
        $this->db()->transaction(function () use ($id, $p) {
            $this->allocs->releaseSource('ap', $id);
            $this->db()->table('vendor_payments')->where('id', $id)->delete();
            if ($p->entry_id) {
                $this->db()->table('entryitems')->where('entry_id', $p->entry_id)->delete();
                $this->db()->table('entries')->where('id', $p->entry_id)->delete();
            }
        });
        ActivityLog::add('Deleted payment '.Allocations::docLabel('', $p->number)." to {$p->vendor_name}");
    }

    // ---- quick expenses: Dr an expense/asset account, Cr a bank/cash account, no vendor ---------------------

    public function expense(int $id): ?object
    {
        return $this->db()->table('expenses')->join('ledgers as el', 'el.id', '=', 'expenses.expense_ledger_id')->join('ledgers as bl', 'bl.id', '=', 'expenses.ledger_id')
            ->leftJoin('items as it', 'it.id', '=', 'expenses.item_id')->where('expenses.id', $id)
            ->first(['expenses.*', 'el.name as expense_ledger_name', 'bl.name as bank_ledger_name', 'it.name as item_name']);
    }

    public function expenseLabel($number): string
    {
        return 'EXP-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public function recordExpense(array $d): int
    {
        $this->guardBooks($d['date'] ?? null);
        $expense = Ledger::find($d['expense_ledger_id']);
        if (! $expense || (int) $expense->type !== 0) {
            $this->fail('Select a valid Expense/Asset account.');
        }
        $bank = Ledger::find($d['ledger_id']);
        if (! $bank || (int) $bank->type !== 1) {
            $this->fail('Select a valid bank or cash account to pay from.');
        }
        $amount = Money::round(str_replace(',', '', (string) $d['amount']));
        if (! Money::positive($amount)) {
            $this->fail('Enter an amount greater than zero.');
        }
        $type = $this->entries->typeByLabel('payment');
        $number = $this->nextNumber('expenses');
        $label = $this->expenseLabel($number);

        return $this->db()->transaction(function () use ($d, $expense, $amount, $type, $number, $label) {
            $entryId = $this->db()->table('entries')->insertGetId(['entrytype_id' => $type->id, 'tag_id' => null, 'number' => $this->entries->nextNumber($type->id), 'date' => $d['date'],
                'dr_total' => $amount, 'cr_total' => $amount, 'notes' => "Expense $label - {$expense->name}"]);
            $project = ! empty($d['project_id']) && Tables::has('entryitems', 'project_id') ? ['project_id' => (int) $d['project_id'], 'cost_type' => $d['cost_type']] : [];
            $this->db()->table('entryitems')->insert([
                ['entry_id' => $entryId, 'ledger_id' => $expense->id, 'amount' => $amount, 'dc' => 'D', 'narration' => "Expense $label - {$expense->name}"] + $project,
                ['entry_id' => $entryId, 'ledger_id' => $d['ledger_id'], 'amount' => $amount, 'dc' => 'C', 'narration' => "Expense $label"],
            ]);
            $id = $this->db()->table('expenses')->insertGetId([
                'expense_ledger_id' => $expense->id, 'item_id' => $d['item_id'] ?: null, 'ledger_id' => $d['ledger_id'], 'entry_id' => $entryId, 'number' => $number, 'date' => $d['date'],
                'amount' => $amount, 'method' => $d['method'] ?? null, 'reference' => $d['reference'] ?? null, 'notes' => $d['notes'] ?? null, 'status' => 'paid', 'created_at' => now(),
            ] + (Tables::has('expenses', 'project_id') ? ['project_id' => $project['project_id'] ?? null, 'cost_type' => $project['cost_type'] ?? null] : []));
            ActivityLog::add("Recorded $label - {$expense->name}");

            return $id;
        });
    }

    public function voidExpense(int $id): void
    {
        $e = $this->expense($id) ?? $this->fail('Expense not found.');
        $this->guardBooks($e->date);
        if ($e->status !== 'paid') {
            $this->fail('Only paid expenses can be voided.');
        }
        $this->db()->transaction(function () use ($id, $e) {
            $this->db()->table('expenses')->where('id', $id)->update(['entry_id' => null, 'status' => 'void']);
            if ($e->entry_id) {
                $this->db()->table('entryitems')->where('entry_id', $e->entry_id)->delete();
                $this->db()->table('entries')->where('id', $e->entry_id)->delete();
            }
        });
        ActivityLog::add('Voided '.$this->expenseLabel($e->number)." - {$e->expense_ledger_name}");
    }

    public function deleteExpense(int $id): void
    {
        $e = $this->expense($id) ?? $this->fail('Expense not found.');
        if ($this->chart->companyLocked()) {
            $this->fail('This account is locked. Unlock it under Account Settings first.');
        }
        if ($e->status !== 'void') {
            $this->fail("A paid expense can't be deleted. Void it first (it stays on record as Void) - then it can be deleted.");
        }
        $this->db()->transaction(function () use ($id, $e) {
            $this->db()->table('expenses')->where('id', $id)->delete();
            if ($e->entry_id) {
                $this->db()->table('entryitems')->where('entry_id', $e->entry_id)->delete();
                $this->db()->table('entries')->where('id', $e->entry_id)->delete();
            }
        });
        ActivityLog::add('Deleted '.$this->expenseLabel($e->number)." - {$e->expense_ledger_name}");
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['purchases' => $message]);
    }
}
