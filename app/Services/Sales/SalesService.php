<?php

namespace App\Services\Sales;

use App\Models\Tenant\Ledger;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Services\Inventory\ItemService;
use App\Services\Inventory\StockService;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sales: quotations, invoices (which post to the books when issued) and customer payments.
 * Ported from the legacy Sales_model.
 *
 * An invoice is a draft until issued. Issuing posts one journal entry:
 *   Dr the customer's own account (the invoice total)
 *   Cr each line's income account (full line amount), Cr tax
 *   Dr Discounts allowed (all discounts), and for stock items Dr Cost of Goods Sold / Cr Inventory Asset.
 * Voiding or reverting removes that entry and puts the stock back. Payments post Dr bank / Cr customer.
 */
class SalesService
{
    public function __construct(
        protected Allocations $allocs,
        protected ChartOfAccounts $chart,
        protected EntryService $entries,
        protected ItemService $items,
    ) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    // ---- settings -------------------------------------------------------------

    public function settings(): object
    {
        return $this->db()->table('sales_settings')->where('id', 1)->first()
            ?? (object) ['ar_group_id' => null, 'sales_ledger_id' => null, 'tax_ledger_id' => null, 'entrytype_id' => null, 'discount_ledger_id' => null,
                'bad_debt_ledger_id' => null, 'quotation_prefix' => 'QTN-', 'invoice_prefix' => 'INV-', 'bank_name' => null, 'bank_account_name' => null, 'bank_iban' => null, 'bank_account_number' => null];
    }

    public function saveSettings(array $d): void
    {
        $cols = array_filter($d, fn ($v, $k) => Tables::has('sales_settings', $k), ARRAY_FILTER_USE_BOTH);
        $q = $this->db()->table('sales_settings');
        $q->where('id', 1)->exists() ? $q->where('id', 1)->update($cols) : $q->insert(['id' => 1] + $cols);
    }

    public function label(string $kind, $number): string
    {
        $s = $this->settings();
        $prefix = $kind === 'quotation' ? ($s->quotation_prefix ?: 'QTN-') : ($s->invoice_prefix ?: 'INV-');

        return $prefix.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    public function nextNumber(string $table): int
    {
        return ((int) $this->db()->table($table)->max('number')) + 1;
    }

    public function numberTaken(string $table, int $number, ?int $exceptId = null): bool
    {
        return $this->db()->table($table)->where('number', $number)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    // ---- totals ----------------------------------------------------------------------

    /** 'percent' / 'amount', or null for "no discount" (blank, zero or anything else). */
    public static function discountType($type, $value): ?string
    {
        $type = strtolower(trim((string) $type));
        $value = str_replace(',', '', trim((string) $value));
        if (! in_array($type, ['percent', 'amount'], true) || $value === '' || ! is_numeric($value) || (float) $value <= 0) {
            return null;
        }

        return $type;
    }

    /** The discount in money on $base: never negative and never more than $base itself. */
    public static function discountAmount($base, ?string $type, $value): string
    {
        if ($type === null || ! Money::positive($base)) {
            return '0.00';
        }
        $value = str_replace(',', '', (string) $value);
        $amount = $type === 'percent' ? Money::percentOf($base, min(100, (float) $value)) : Money::round($value);
        if (Money::cmp($amount, $base) > 0) {
            $amount = Money::add($base, 0);
        }

        return Money::cmp($amount, 0) < 0 ? '0.00' : $amount;
    }

    /**
     * Apply line and document discounts. Each item needs `amount`; optional discount_type/discount_value.
     * Returns the items with discount_amount filled in plus subtotal, discounts and the taxable amount.
     */
    public function documentTotals(array $items, $docType, $docValue): array
    {
        $subtotal = '0.00';
        $lineDiscounts = '0.00';
        foreach ($items as $k => $item) {
            $type = self::discountType($item['discount_type'] ?? null, $item['discount_value'] ?? null);
            $disc = self::discountAmount($item['amount'], $type, $item['discount_value'] ?? null);
            $items[$k]['discount_type'] = $type;
            $items[$k]['discount_value'] = $type ? str_replace(',', '', (string) $item['discount_value']) : null;
            $items[$k]['discount_amount'] = $disc;
            $subtotal = Money::add($subtotal, Money::sub($item['amount'], $disc));
            $lineDiscounts = Money::add($lineDiscounts, $disc);
        }
        $dType = self::discountType($docType, $docValue);
        $dDisc = self::discountAmount($subtotal, $dType, $docValue);

        return [
            'items' => $items, 'subtotal' => $subtotal, 'discount_type' => $dType, 'discount_value' => $dType ? str_replace(',', '', (string) $docValue) : null,
            'discount_amount' => $dDisc, 'discount_total' => Money::add($lineDiscounts, $dDisc), 'taxable' => Money::sub($subtotal, $dDisc),
        ];
    }

    /** Tax from a percentage of the taxable amount, or the typed tax total when there is no percentage. */
    public function tax(string $taxable, array $d): array
    {
        $pct = isset($d['tax_percent']) && $d['tax_percent'] !== '' && $d['tax_percent'] !== null ? $d['tax_percent'] : null;
        if ($pct === null) {
            return [Money::add($d['tax_total'] ?? 0, 0), null];
        }

        return [Money::percentOf($taxable, $pct), $pct];
    }

    /** One line's amount: qty x unit price, rounded to cents. */
    public function lineAmount($qty, $price): string
    {
        return Money::mul($qty, $price);
    }

    // ---- quotations and invoices (they share one shape) ---------------------------------

    /** Write the document header and replace its lines. $table: quotations | invoices. */
    protected function saveDoc(string $table, array $d, array $lines, ?int $id): int
    {
        $itemTable = $table === 'quotations' ? 'quotation_items' : 'invoice_items';
        $fk = $table === 'quotations' ? 'quotation_id' : 'invoice_id';
        $t = $this->documentTotals($lines, $d['discount_type'] ?? null, $d['discount_value'] ?? null);
        [$tax, $pct] = $this->tax($t['taxable'], $d);

        $row = [
            'customer_id' => $d['customer_id'], 'date' => $d['date'], 'notes' => $d['notes'] ?? null, 'subtotal' => $t['subtotal'], 'tax_total' => $tax, 'tax_percent' => $pct,
            'total' => Money::add($t['taxable'], $tax), 'discount_type' => $t['discount_type'], 'discount_value' => $t['discount_value'],
            'discount_amount' => $t['discount_amount'], 'discount_total' => $t['discount_total'],
        ] + ($table === 'quotations' ? ['expiry_date' => $d['expiry_date'] ?? null] : ['due_date' => $d['due_date'] ?? null]);
        if ($table === 'invoices' && array_key_exists('quotation_id', $d)) {
            $row['quotation_id'] = $d['quotation_id'];
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

        foreach (array_values($t['items']) as $i => $it) {
            $this->db()->table($itemTable)->insert([
                $fk => $id, 'item_id' => $it['item_id'] ?: null, 'description' => $it['description'], 'qty' => $it['qty'], 'unit_price' => $it['unit_price'],
                'amount' => $it['amount'], 'discount_type' => $it['discount_type'], 'discount_value' => $it['discount_value'], 'discount_amount' => $it['discount_amount'], 'sort_order' => $i,
            ] + ($table === 'invoices' ? ['cogs_amount' => null] : []));
        }
        if (array_key_exists('vehicle_ids', $d)) {
            $this->saveVehicles($table === 'quotations' ? 'quotation_vehicles' : 'invoice_vehicles', $fk, $id, (int) $d['customer_id'], (array) $d['vehicle_ids']);
        }

        return $id;
    }

    /** The customer's own vehicles a document is for, in the order picked. */
    protected function saveVehicles(string $table, string $fk, int $docId, int $customerId, array $ids): void
    {
        if (! Tables::has($table)) {
            return;
        }
        $valid = $this->db()->table('customer_vehicles')->where('customer_id', $customerId)->whereIn('id', array_map('intval', $ids))->pluck('id')->flip();
        $this->db()->table($table)->where($fk, $docId)->delete();
        $i = 0;
        foreach ($ids as $vid) {
            if ($valid->has((int) $vid)) {
                $this->db()->table($table)->insert([$fk => $docId, 'vehicle_id' => (int) $vid, 'sort_order' => $i++]);
            }
        }
    }

    public function saveQuotation(array $d, array $lines, ?int $id = null): int
    {
        return $this->db()->transaction(fn () => $this->saveDoc('quotations', $d, $lines, $id));
    }

    public function saveInvoice(array $d, array $lines, ?int $id = null): int
    {
        return $this->db()->transaction(fn () => $this->saveDoc('invoices', $d, $lines, $id));
    }

    public function quotation(int $id): ?object
    {
        $q = $this->db()->table('quotations')->join('customers', 'customers.id', '=', 'quotations.customer_id')->where('quotations.id', $id)
            ->first(['quotations.*', 'customers.name as customer_name', 'customers.address as customer_address', 'customers.email as customer_email', 'customers.phone as customer_phone']);
        if ($q) {
            $q->items = $this->db()->table('quotation_items')->where('quotation_id', $id)->orderBy('sort_order')->get();
            $q->vehicle_ids = Tables::has('quotation_vehicles') ? $this->db()->table('quotation_vehicles')->where('quotation_id', $id)->orderBy('sort_order')->pluck('vehicle_id')->all() : [];
        }

        return $q;
    }

    public function invoice(int $id): ?object
    {
        $i = $this->db()->table('invoices')->join('customers', 'customers.id', '=', 'invoices.customer_id')->where('invoices.id', $id)
            ->first(['invoices.*', 'customers.name as customer_name', 'customers.address as customer_address', 'customers.email as customer_email',
                'customers.phone as customer_phone', 'customers.ledger_id as customer_ledger_id']);
        if ($i) {
            $i->items = $this->db()->table('invoice_items')->where('invoice_id', $id)->orderBy('sort_order')->get();
            $i->vehicle_ids = Tables::has('invoice_vehicles') ? $this->db()->table('invoice_vehicles')->where('invoice_id', $id)->orderBy('sort_order')->pluck('vehicle_id')->all() : [];
        }

        return $i;
    }

    /** Unlink any invoice made from it first (the invoice and what it posted stay), then remove. */
    public function deleteQuotation(int $id): void
    {
        $this->db()->transaction(function () use ($id) {
            $this->db()->table('invoices')->where('quotation_id', $id)->update(['quotation_id' => null]);
            $this->db()->table('quotation_items')->where('quotation_id', $id)->delete();
            if (Tables::has('quotation_vehicles')) {
                $this->db()->table('quotation_vehicles')->where('quotation_id', $id)->delete();
            }
            $this->db()->table('quotations')->where('id', $id)->delete();
        });
    }

    /** A quotation becomes a draft invoice with the same lines, tax and discount; the quotation is marked converted. */
    public function convertQuotation(int $id): int
    {
        return $this->db()->transaction(function () use ($id) {
            $q = $this->quotation($id) ?? $this->fail('Quotation not found.');
            $status = $this->db()->selectOne('SELECT status FROM quotations WHERE id = ? FOR UPDATE', [$id])?->status;
            if ($q->status === 'converted' || $status === 'converted') {
                $this->fail('This quotation was already converted.');
            }
            $lines = $q->items->map(fn ($i) => ['item_id' => $i->item_id, 'description' => $i->description, 'qty' => $i->qty, 'unit_price' => $i->unit_price,
                'amount' => $i->amount, 'discount_type' => $i->discount_type, 'discount_value' => $i->discount_value])->all();
            $invoiceId = $this->saveDoc('invoices', [
                'customer_id' => $q->customer_id, 'vehicle_ids' => $q->vehicle_ids, 'quotation_id' => $id, 'date' => now()->toDateString(), 'due_date' => null,
                'notes' => $q->notes, 'tax_total' => $q->tax_total, 'tax_percent' => $q->tax_percent, 'discount_type' => $q->discount_type, 'discount_value' => $q->discount_value,
            ], $lines, null);
            $this->db()->table('quotations')->where('id', $id)->update(['status' => 'converted']);

            return $invoiceId;
        });
    }

    // ---- invoice posting ---------------------------------------------------------------

    /** Lines sold below the item's cost - a warning only: "Oil 5L at $1.50, cost $2.00". */
    public function belowCost(object $invoice): array
    {
        $out = [];
        foreach ($invoice->items as $l) {
            if (! $l->item_id || ! Money::positive($l->qty)) {
                continue;
            }
            $item = $this->db()->table('items')->where('id', $l->item_id)->first(['name', 'purchase_cost']);
            if (! $item || ! Money::positive($item->purchase_cost)) {
                continue;
            }
            $net = Money::sub($l->amount, $l->discount_amount ?? 0);
            if (Money::cmp($net, Money::mul($l->qty, $item->purchase_cost)) < 0) {
                $out[] = $item->name.' at '.Money::format(Money::round(bcdiv($net, (string) $l->qty, 8))).', cost '.Money::format($item->purchase_cost);
            }
        }

        return $out;
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

    /** The "no stock below zero" check, run under row locks. Null when everything is there, else the message. */
    public function stockShortfall(array $needs, string $result, string $advice = 'Record the purchase (bill) or a stock adjustment first.'): ?string
    {
        $short = [];
        foreach ($needs as $itemId => $qty) {
            if (! Money::positive($qty)) {
                continue;
            }
            $item = $this->db()->selectOne('SELECT id, name, type, quantity_on_hand FROM items WHERE id = ? FOR UPDATE', [$itemId]);
            if (! $item || $item->type !== 'inventory') {
                continue;
            }
            if (Money::cmp($item->quantity_on_hand, $qty) < 0) {
                $have = Money::cmp($item->quantity_on_hand, 0) < 0 ? '0' : StockService::text($item->quantity_on_hand);
                $short[] = "{$item->name} ($have in stock, needs ".StockService::text($qty).')';
            }
        }

        return $short ? 'Not enough stock: '.implode('; ', $short).". $result $advice" : null;
    }

    /** Discounts allowed account: the saved one, else "Discounts allowed" if the chart has it, else a new one next to Sales. */
    public function discountLedger(): ?int
    {
        $s = $this->settings();
        if ($s->discount_ledger_id && Ledger::where('id', $s->discount_ledger_id)->exists()) {
            return (int) $s->discount_ledger_id;
        }
        $l = Ledger::where('code', '03-01-01-0002')->first() ?? Ledger::where('name', 'like', '%Discounts allowed%')->first() ?? Ledger::where('name', 'like', '%Discount allowed%')->first();
        if (! $l) {
            $sales = $s->sales_ledger_id ? Ledger::find($s->sales_ledger_id) : null;
            if (! $sales) {
                return null;
            }
            $l = Ledger::create(['group_id' => $sales->group_id, 'name' => 'Discounts Allowed', 'code' => $this->chart->nextLedgerCode($sales->group_id), 'op_balance' => '0.00',
                'op_balance_dc' => 'D', 'type' => 0, 'reconciliation' => 0, 'is_contra' => 1, 'notes' => 'Discounts given to customers on invoices (created automatically)']);
            ActivityLog::add('Created ledger "Discounts Allowed" for invoice discounts');
        } elseif (! $l->is_contra) {
            $l->update(['is_contra' => 1]); // it sits under Income but carries a debit balance
        }
        $this->saveSettings(['discount_ledger_id' => $l->id]);

        return (int) $l->id;
    }

    public function issueInvoice(int $id): void
    {
        if ($this->chart->companyLocked()) {
            $this->fail('This account is locked. Unlock it under Account Settings before issuing invoices.');
        }
        $inv = $this->invoice($id) ?? $this->fail('Invoice not found.');
        if ($inv->status !== 'draft') {
            $this->fail('Only draft invoices can be issued.');
        }
        if ($inv->items->isEmpty()) {
            $this->fail('Add at least one line item before issuing.');
        }
        $this->guardBooks($inv->date);

        $s = $this->settings();
        if (! $s->sales_ledger_id || ! $s->entrytype_id) {
            $this->fail('Sales account is not configured. Set it under Sales → Settings first.');
        }
        if (Money::positive($inv->tax_total) && ! $s->tax_ledger_id) {
            $this->fail('This invoice has tax but no Tax account is configured under Sales → Settings.');
        }

        // cost of stock sold, and which account each line is credited to
        $itemSettings = null;
        $cogsTotal = '0.00';
        $lineCogs = [];
        $lineLedger = [];
        $stock = [];
        foreach ($inv->items as $l) {
            $item = $l->item_id ? $this->db()->table('items')->where('id', $l->item_id)->first() : null;
            $lineLedger[$l->id] = $item && $item->sales_ledger_id ? $item->sales_ledger_id : $s->sales_ledger_id;
            if (! $item || $item->type !== 'inventory') {
                continue;
            }
            if (! empty($item->track_batches)) {
                $this->fail("{$item->name} is tracked by batch and expiry date, which isn't supported on invoices in the new system yet. Turn batch tracking off for it on the item page, or issue this invoice in the old system.");
            }
            if ($itemSettings === null) {
                $this->items->ensureInventoryAccounts();
                $itemSettings = $this->items->settings();
                if (! $itemSettings->inventory_asset_ledger_id || ! $itemSettings->cogs_ledger_id) {
                    $this->fail('This invoice sells an Inventory item, but the Inventory Asset / Cost of Goods Sold accounts are not configured. Set them under Items → Settings first.');
                }
            }
            $cost = Money::mul($l->qty, $item->purchase_cost);
            $lineCogs[$l->id] = $cost;
            $cogsTotal = Money::add($cogsTotal, $cost);
            $stock[$item->id] = Money::add($stock[$item->id] ?? 0, $l->qty);
        }

        $discountTotal = $inv->discount_total ?? '0.00';
        $discountLedger = null;
        if (Money::positive($discountTotal) && ! ($discountLedger = $this->discountLedger())) {
            $this->fail('This invoice has a discount but no Discounts account could be found or created. Pick one under Sales → Settings first.');
        }

        // never post an entry that doesn't balance
        $cr = Money::add($inv->tax_total, $cogsTotal);
        foreach ($inv->items as $l) {
            $cr = Money::add($cr, $l->amount);
        }
        $dr = Money::add(Money::add($inv->total, $discountTotal), $cogsTotal);
        if (Money::cmp($dr, $cr) !== 0) {
            $this->fail("This invoice's totals don't add up (debits $dr, credits $cr), so it was not posted. Open it, press Save once to recalculate, then issue again.");
        }
        // a deduction line (negative amount) is posted as a debit to its own account, so both sides grow by it
        $entryTotal = $dr;
        foreach ($inv->items as $l) {
            if (Money::cmp($l->amount, 0) < 0) {
                $entryTotal = Money::add($entryTotal, Money::neg($l->amount));
            }
        }

        $this->db()->transaction(function () use ($id, $inv, $s, $lineLedger, $lineCogs, $stock, $cogsTotal, $discountTotal, $discountLedger, $entryTotal, $itemSettings) {
            if ($this->lockStatus('invoices', $id) !== 'draft') {
                $this->fail('Only draft invoices can be issued.');
            }
            if ($stock && ($short = $this->stockShortfall($stock, 'The invoice was not issued - it is still a draft.'))) {
                $this->fail($short);
            }

            $note = "Sales Invoice #{$inv->number}";
            $entryId = $this->db()->table('entries')->insertGetId([
                'entrytype_id' => $s->entrytype_id, 'tag_id' => null, 'number' => $this->entries->nextNumber($s->entrytype_id), 'date' => substr($inv->date, 0, 10),
                'dr_total' => $entryTotal, 'cr_total' => $entryTotal, 'notes' => "$note - {$inv->customer_name}",
            ]);
            $lines = [['ledger_id' => $inv->customer_ledger_id, 'amount' => $inv->total, 'dc' => 'D', 'narration' => $note]];
            foreach ($inv->items as $l) {
                $deduct = Money::cmp($l->amount, 0) < 0;
                $lines[] = ['ledger_id' => $lineLedger[$l->id], 'amount' => $deduct ? Money::neg($l->amount) : $l->amount, 'dc' => $deduct ? 'D' : 'C', 'narration' => "$note - {$l->description}"];
            }
            if (Money::positive($inv->tax_total)) {
                $lines[] = ['ledger_id' => $s->tax_ledger_id, 'amount' => $inv->tax_total, 'dc' => 'C', 'narration' => "$note - Tax"];
            }
            if ($discountLedger) {
                $lines[] = ['ledger_id' => $discountLedger, 'amount' => $discountTotal, 'dc' => 'D', 'narration' => "$note - Discount"];
            }
            if (Money::positive($cogsTotal)) {
                $lines[] = ['ledger_id' => $itemSettings->cogs_ledger_id, 'amount' => $cogsTotal, 'dc' => 'D', 'narration' => "$note - Cost of Goods Sold"];
                $lines[] = ['ledger_id' => $itemSettings->inventory_asset_ledger_id, 'amount' => $cogsTotal, 'dc' => 'C', 'narration' => "$note - Cost of Goods Sold"];
            }
            $this->db()->table('entryitems')->insert(array_map(fn ($l) => $l + ['entry_id' => $entryId], $lines));

            // stock leaves on every inventory line, also at 0 cost; cogs_amount (even 0.00) marks the line as having taken stock
            foreach ($lineCogs as $lineId => $cost) {
                $this->db()->table('invoice_items')->where('id', $lineId)->update(['cogs_amount' => $cost]);
            }
            foreach ($stock as $itemId => $qty) {
                $this->db()->update('UPDATE items SET quantity_on_hand = quantity_on_hand - ? WHERE id = ?', [$qty, $itemId]);
            }
            $this->db()->table('invoices')->where('id', $id)->update(['entry_id' => $entryId, 'status' => 'issued']);
        });
        ActivityLog::add("Issued Sales Invoice #{$inv->number} for {$inv->customer_name}");
    }

    /** Put the stock of an issued invoice back and remove its journal entry. Returns the invoice. */
    protected function unpost(object $inv, string $newStatus): void
    {
        $this->allocs->releaseDoc('ar', $inv->id); // payments applied to it become unapplied credit again
        $entryId = $inv->entry_id;
        $this->db()->table('invoices')->where('id', $inv->id)->update(['entry_id' => null, 'status' => $newStatus]);
        if ($entryId) {
            $this->db()->table('entryitems')->where('entry_id', $entryId)->delete();
            $this->db()->table('entries')->where('id', $entryId)->delete();
        }
        foreach ($inv->items as $l) {
            if (! $l->item_id || $l->cogs_amount === null) {
                continue;
            }
            $this->db()->update("UPDATE items SET quantity_on_hand = quantity_on_hand + ? WHERE id = ? AND type = 'inventory'", [$l->qty, $l->item_id]);
        }
    }

    public function voidInvoice(int $id): void
    {
        $inv = $this->invoice($id) ?? $this->fail('Invoice not found.');
        $this->guardBooks($inv->date);
        if ($inv->status !== 'issued') {
            $this->fail('Only issued invoices can be voided.');
        }
        $this->db()->transaction(function () use ($id, $inv) {
            if ($this->lockStatus('invoices', $id) !== 'issued') {
                $this->fail('Only issued invoices can be voided.');
            }
            $this->unpost($inv, 'void');
        });
        ActivityLog::add("Voided Sales Invoice #{$inv->number} for {$inv->customer_name}");
    }

    /** Back to draft so the same invoice (same number) can be corrected and re-issued. */
    public function revertInvoice(int $id): void
    {
        $inv = $this->invoice($id) ?? $this->fail('Invoice not found.');
        $this->guardBooks($inv->date);
        if ($inv->status !== 'issued') {
            $this->fail('Only issued invoices can be reverted to draft.');
        }
        $this->db()->transaction(function () use ($id, $inv) {
            if ($this->lockStatus('invoices', $id) !== 'issued') {
                $this->fail('Only issued invoices can be reverted to draft.');
            }
            $this->unpost($inv, 'draft');
        });
        ActivityLog::add("Reverted Sales Invoice #{$inv->number} for {$inv->customer_name} back to draft");
    }

    /** Draft or void only: an issued invoice is part of the books (void it or revert it first). */
    public function deleteInvoice(int $id): ?int
    {
        $inv = $this->invoice($id) ?? $this->fail('Invoice not found.');
        if ($inv->status === 'issued') {
            $this->fail("An issued invoice can't be deleted. Void it first (it stays on record as Void), or revert it to draft to fix it - then it can be deleted.");
        }
        $this->db()->transaction(function () use ($id, $inv) {
            $this->allocs->releaseDoc('ar', $id);
            $this->db()->table('invoice_items')->where('invoice_id', $id)->delete();
            if (Tables::has('invoice_vehicles')) {
                $this->db()->table('invoice_vehicles')->where('invoice_id', $id)->delete();
            }
            $this->db()->table('invoices')->where('id', $id)->delete();
            // the source quotation is back to draft so it can be converted again
            if ($inv->quotation_id) {
                $this->db()->table('quotations')->where('id', $inv->quotation_id)->update(['status' => 'draft']);
            }
        });
        ActivityLog::add("Deleted Invoice #{$inv->number}");

        return $inv->quotation_id;
    }

    /** An issued invoice only lets its due date and notes change; its amounts are what the posted entry reflects. */
    public function updateIssuedDetails(int $id, ?string $dueDate, ?string $notes): void
    {
        $inv = $this->invoice($id) ?? $this->fail('Invoice not found.');
        if ($inv->status !== 'issued') {
            $this->fail('This quick edit is only for issued invoices.');
        }
        $this->db()->table('invoices')->where('id', $id)->update(['due_date' => $dueDate ?: null, 'notes' => $notes]);
    }

    // ---- payments --------------------------------------------------------------------------

    public function payment(int $id): ?object
    {
        $p = $this->db()->table('payments')->join('customers', 'customers.id', '=', 'payments.customer_id')->join('ledgers', 'ledgers.id', '=', 'payments.ledger_id')
            ->where('payments.id', $id)->first(['payments.*', 'customers.name as customer_name', 'customers.ledger_id as customer_ledger_id', 'ledgers.name as ledger_name']);
        if ($p && $p->wht_ledger_id) {
            $p->wht_ledger_name = $this->db()->table('ledgers')->where('id', $p->wht_ledger_id)->value('name');
        }

        return $p;
    }

    /** Splits a payment into tax withheld by the customer and what reached the bank. $rate is a percent (3 = 3%). */
    public function withholdingSplit(string $gross, $rate): array
    {
        $rate = trim(str_replace(['%', ','], ['', '.'], (string) $rate));
        $rate = $rate === '' ? '0' : $rate;
        if (! is_numeric($rate) || (float) $rate < 0 || (float) $rate >= 100) {
            $this->fail('The tax withheld must be a percent from 0 to less than 100.');
        }
        $rate = number_format((float) $rate, 4, '.', '');
        $amount = (float) $rate > 0 ? Money::percentOf($gross, $rate) : '0.00';
        $net = Money::sub($gross, $amount);
        if (! Money::positive($net)) {
            $this->fail('The tax withheld leaves nothing paid into the bank. Check the rate.');
        }

        return ['rate' => $rate, 'label' => rtrim(rtrim($rate, '0'), '.'), 'amount' => $amount, 'net' => $net];
    }

    /** Withholding Tax Expense: a final tax (never claimed back), so an expense. Found by name among the expense accounts, else created. */
    public function whtLedger(): ?int
    {
        $root = $this->chart->topLevelGroupId('expense');
        if (! $root) {
            return null;
        }
        $under = $this->chart->descendantLedgerIds($root);
        foreach (['withholding tax expense', 'withholding tax', 'tax withheld'] as $p) {
            $l = Ledger::whereIn('id', $under)->where('type', 0)->where('name', 'like', "%$p%")->where('name', 'not like', '%payable%')->where('name', 'not like', '%receivable%')->orderBy('id')->first();
            if ($l) {
                return (int) $l->id;
            }
        }
        $near = Ledger::whereIn('id', $under)->where('name', 'like', '%income tax expense%')->first();
        $groupId = $near?->group_id ?? $root;

        return Ledger::create(['group_id' => $groupId, 'name' => 'Withholding Tax Expense', 'code' => $this->chart->nextLedgerCode($groupId), 'op_balance' => '0.00', 'op_balance_dc' => 'D',
            'type' => 0, 'reconciliation' => 0, 'notes' => 'Tax withheld by customers on payments (created automatically)'])->id;
    }

    /** Whether the payment form offers "tax withheld by the customer" (the two companies that use it, by name - as in the old app). */
    public function withholdingEnabled(): bool
    {
        $name = strtolower(trim((string) DB::connection('tenant')->table('settings')->value('name')));

        return in_array($name, ['iskaashi auto service', 'rasmi logistic service ltd'], true);
    }

    /** The payment number typed on the form, or the next free one. */
    public function resolvePaymentNumber($typed, ?int $exceptId = null): int
    {
        $typed = trim((string) $typed);
        if ($typed === '') {
            return $this->nextNumber('payments');
        }
        if (! ctype_digit($typed) || (int) $typed <= 0 || strlen($typed) > 12) {
            $this->fail('The payment number must be a whole number (for example 928).');
        }
        if ($this->numberTaken('payments', (int) $typed, $exceptId)) {
            $this->fail("Payment number $typed is already used by another payment. Choose a different one.");
        }

        return (int) $typed;
    }

    /** Same as resolvePaymentNumber() for another payments table (vendor_payments). */
    public function resolvePaymentNumberFor(string $table, $typed, ?int $exceptId = null): int
    {
        $typed = trim((string) $typed);
        if ($typed === '') {
            return $this->nextNumber($table);
        }
        if (! ctype_digit($typed) || (int) $typed <= 0 || strlen($typed) > 12) {
            $this->fail('The payment number must be a whole number (for example 928).');
        }
        if ($this->numberTaken($table, (int) $typed, $exceptId)) {
            $this->fail("Payment number $typed is already used by another payment. Choose a different one.");
        }

        return (int) $typed;
    }

    /** Record a customer payment: Dr the bank/cash account, Cr the customer; tax withheld goes to its expense account. Optionally applied to invoices. */
    public function receivePayment(array $d): int
    {
        $this->guardBooks($d['date'] ?? null);
        $customer = $this->db()->table('customers')->where('id', $d['customer_id'])->first() ?? $this->fail('Customer not found.');
        $bank = Ledger::find($d['ledger_id']);
        if (! $bank || (int) $bank->type !== 1) {
            $this->fail('Select a valid bank or cash account to deposit the payment into.');
        }
        $amount = Money::round(str_replace(',', '', (string) $d['amount']));
        if (! Money::positive($amount)) {
            $this->fail('Enter a payment amount greater than zero.');
        }
        $type = $this->entries->typeByLabel('receipt');
        $number = $this->resolvePaymentNumber($d['number'] ?? null);
        $label = Allocations::docLabel('', $number);

        $wht = $this->withholdingSplit($amount, $d['wht_rate'] ?? 0);
        $whtLedger = Money::positive($wht['amount']) ? ($this->whtLedger() ?? $this->fail('Could not find or create the Withholding Tax Expense account. The payment was not saved.')) : null;

        return $this->db()->transaction(function () use ($d, $customer, $amount, $type, $number, $label, $wht, $whtLedger) {
            $entryId = $this->db()->table('entries')->insertGetId([
                'entrytype_id' => $type->id, 'tag_id' => null, 'number' => $this->entries->nextNumber($type->id), 'date' => $d['date'], 'dr_total' => $amount, 'cr_total' => $amount,
                'notes' => "Payment $label received from {$customer->name}".($whtLedger ? " ({$wht['label']}% tax withheld by the customer)" : ''),
            ]);
            $lines = [['ledger_id' => $d['ledger_id'], 'amount' => $wht['net'], 'dc' => 'D', 'narration' => "Payment $label received from {$customer->name}"]];
            if ($whtLedger) {
                $lines[] = ['ledger_id' => $whtLedger, 'amount' => $wht['amount'], 'dc' => 'D', 'narration' => "Tax withheld by {$customer->name} ({$wht['label']}%) on payment $label"];
            }
            $lines[] = ['ledger_id' => $customer->ledger_id, 'amount' => $amount, 'dc' => 'C', 'narration' => "Payment $label"];
            $this->db()->table('entryitems')->insert(array_map(fn ($l) => $l + ['entry_id' => $entryId], $lines));

            $id = $this->db()->table('payments')->insertGetId([
                'customer_id' => $customer->id, 'ledger_id' => $d['ledger_id'], 'entry_id' => $entryId, 'number' => $number, 'date' => $d['date'], 'amount' => $amount,
                'wht_rate' => $whtLedger ? $wht['rate'] : null, 'wht_amount' => $whtLedger ? $wht['amount'] : '0.00', 'wht_ledger_id' => $whtLedger,
                'method' => $d['method'] ?? null, 'reference' => $d['reference'] ?? null, 'notes' => $d['notes'] ?? null, 'status' => 'received', 'created_at' => now(),
            ]);
            // apply it to the invoices ticked on the form; whatever is not applied stays on the customer as credit
            if (! empty($d['apply'])) {
                $this->allocs->allocate('ar', $id, $d['apply'], 'payment');
            }
            ActivityLog::add("Received payment $label from {$customer->name}");

            return $id;
        });
    }

    /** How much of a payment is already in use: applied to invoices plus refunded. */
    public function paymentUsed(int $id): string
    {
        return Money::add($this->allocs->appliedBySource('ar', [$id])[$id] ?? 0, $this->allocs->refundedBySource('ar', [$id], 'pay')[$id] ?? 0);
    }

    /**
     * Edit a received payment in place: date, deposit account, amount, tax withheld, number, method, reference, notes.
     * The customer stays the same (a payment from the wrong customer is voided and entered again). Its journal entry keeps
     * its id and number; date, totals and lines are rewritten in one transaction. It stays applied to its invoices.
     */
    public function updatePayment(int $id, array $d): array
    {
        $this->guardBooks($d['date'] ?? null);
        $p = $this->payment($id) ?? $this->fail('Payment not found.');
        if ($p->status !== 'received' || ! $p->entry_id) {
            $this->fail('Only a received payment can be edited.');
        }
        if ($err = $this->entries->closedPeriodError(substr($p->date, 0, 10))) {
            $this->fail($err);
        }
        $bank = Ledger::find($d['ledger_id']);
        if (! $bank || (int) $bank->type !== 1) {
            $this->fail('Select a valid bank or cash account to deposit the payment into.');
        }
        $amount = Money::round(str_replace(',', '', trim((string) $d['amount'])));
        if (! is_numeric($amount) || ! Money::positive($amount)) {
            $this->fail('Enter a payment amount greater than zero.');
        }
        $used = $this->paymentUsed($id);
        if (Money::cmp($amount, $used) < 0) {
            $this->fail(Money::format($used)." of this payment is already applied to invoices or refunded, so the amount can't be less than that. Remove it from an invoice first (Remove on the payment page), then edit.");
        }
        $number = trim((string) ($d['number'] ?? '')) !== '' && (string) $d['number'] !== (string) $p->number ? $this->resolvePaymentNumber($d['number'], $id) : (int) $p->number;
        $label = Allocations::docLabel('', $number);
        $wht = $this->withholdingSplit($amount, $d['wht_rate'] ?? 0);
        $whtLedger = Money::positive($wht['amount']) ? ($this->whtLedger() ?? $this->fail('Could not find or create the Withholding Tax Expense account. Nothing was changed.')) : null;

        $fields = ['ledger_id' => (int) $d['ledger_id'], 'number' => $number, 'date' => $d['date'], 'amount' => $amount, 'wht_rate' => $whtLedger ? $wht['rate'] : null,
            'wht_amount' => $whtLedger ? $wht['amount'] : '0.00', 'wht_ledger_id' => $whtLedger, 'method' => (string) ($d['method'] ?? ''), 'reference' => (string) ($d['reference'] ?? ''), 'notes' => (string) ($d['notes'] ?? '')];

        $changes = [];
        if (substr($p->date, 0, 10) !== $fields['date']) {
            $changes[] = 'date '.Money::date($p->date).' to '.Money::date($fields['date']);
        }
        if ((int) $p->ledger_id !== $fields['ledger_id']) {
            $changes[] = "deposited to {$p->ledger_name} to {$bank->name}";
        }
        if (Money::cmp($p->amount, $amount) !== 0) {
            $changes[] = 'amount '.Money::format($p->amount).' to '.Money::format($amount);
        }
        if (Money::cmp($p->wht_amount ?? 0, $fields['wht_amount']) !== 0) {
            $changes[] = 'tax withheld '.Money::format($p->wht_amount ?? 0).' to '.Money::format($fields['wht_amount']);
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

        $this->db()->transaction(function () use ($p, $id, $fields, $amount, $wht, $whtLedger, $label) {
            $this->db()->table('entries')->where('id', $p->entry_id)->update([
                'date' => $fields['date'], 'dr_total' => $amount, 'cr_total' => $amount,
                'notes' => "Payment $label received from {$p->customer_name}".($whtLedger ? " ({$wht['label']}% tax withheld by the customer)" : ''),
            ]);
            $this->db()->table('entryitems')->where('entry_id', $p->entry_id)->delete();
            $lines = [['ledger_id' => $fields['ledger_id'], 'amount' => $wht['net'], 'dc' => 'D', 'narration' => "Payment $label received from {$p->customer_name}"]];
            if ($whtLedger) {
                $lines[] = ['ledger_id' => $whtLedger, 'amount' => $wht['amount'], 'dc' => 'D', 'narration' => "Tax withheld by {$p->customer_name} ({$wht['label']}%) on payment $label"];
            }
            $lines[] = ['ledger_id' => $p->customer_ledger_id, 'amount' => $amount, 'dc' => 'C', 'narration' => "Payment $label"];
            $this->db()->table('entryitems')->insert(array_map(fn ($l) => $l + ['entry_id' => $p->entry_id], $lines));
            $this->db()->table('payments')->where('id', $id)->update($fields);
        });
        ActivityLog::add("Edited payment $label from {$p->customer_name}: ".implode(', ', $changes));

        return $changes;
    }

    public function voidPayment(int $id): void
    {
        $p = $this->payment($id) ?? $this->fail('Payment not found.');
        $this->guardBooks($p->date);
        if ($p->status !== 'received') {
            $this->fail('Only received payments can be voided.');
        }
        if ($this->allocs->hasRefunds('ar', 'pay', $id)) {
            $this->fail('Part of this payment was refunded. Delete the refund first (on the payment page).');
        }
        $this->db()->transaction(function () use ($id, $p) {
            $this->allocs->releaseSource('ar', $id); // the invoices it paid become unpaid again
            $this->db()->table('payments')->where('id', $id)->update(['entry_id' => null, 'status' => 'void']);
            if ($p->entry_id) {
                $this->db()->table('entryitems')->where('entry_id', $p->entry_id)->delete();
                $this->db()->table('entries')->where('id', $p->entry_id)->delete();
            }
        });
        ActivityLog::add('Voided payment '.Allocations::docLabel('', $p->number)." from {$p->customer_name}");
    }

    /** Only a voided payment can be deleted for good, so a mistake always leaves a voided record first. */
    public function deletePayment(int $id): void
    {
        $p = $this->payment($id) ?? $this->fail('Payment not found.');
        if ($this->chart->companyLocked()) {
            $this->fail('This account is locked. Unlock it under Account Settings first.');
        }
        if ($p->status !== 'void') {
            $this->fail('Void this payment first. Only a voided payment can be deleted.');
        }
        if ($this->allocs->hasRefunds('ar', 'pay', $id)) {
            $this->fail('Part of this payment was refunded. Delete the refund first (on the payment page).');
        }
        $this->db()->transaction(function () use ($id, $p) {
            $this->allocs->releaseSource('ar', $id);
            $this->db()->table('payments')->where('id', $id)->delete();
            if ($p->entry_id) {
                $this->db()->table('entryitems')->where('entry_id', $p->entry_id)->delete();
                $this->db()->table('entries')->where('id', $p->entry_id)->delete();
            }
        });
        ActivityLog::add('Deleted payment '.Allocations::docLabel('', $p->number)." from {$p->customer_name}");
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['sales' => $message]);
    }
}
