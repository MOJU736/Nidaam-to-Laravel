<?php

namespace App\Services\Settings;

use App\Models\Tenant\Group;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\Setting;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Inventory\ItemService;
use App\Services\Parties\PartyService;
use App\Services\Purchases\PurchasesService;
use App\Services\Sales\SalesService;
use App\Support\Money;
use App\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Move from QuickBooks: brings a company's QuickBooks Desktop books into an EMPTY company in one go - every account balance,
 * every customer with their unpaid invoices, every vendor with their unpaid bills, and the stock on hand.
 * Ported from the legacy Qb_import_model.
 *
 * The input is an "opening balances" workbook (.xlsx) prepared from the QuickBooks reports (Trial Balance, Customer / Vendor
 * Balance Detail, Inventory Valuation). Sheets by name, each with a header row (columns are found by header text):
 *   Start here    - "Opening date" in column A, the date in column B
 *   Accounts      - QuickBooks account | Debit | Credit | Nidaam account | Nidaam group | New group goes under | Bank or cash?
 *                   Nidaam account "(customers)", "(vendors)" or "(items)" marks the Receivable / Payable / Inventory line:
 *                   those come in through the sheets below and must agree with them to the cent.
 *   Customers     - Customer | Phone | Email | Address | Balance in QuickBooks
 *   Open invoices - Customer | Nidaam invoice number | Date | Due date | Amount still owed | Note
 *   Vendors       - Vendor | Phone | Email | Address | Balance in QuickBooks
 *   Open bills    - Vendor | Vendor reference | Date | Due date | Amount still owed | Note
 *   Items         - Item | Quantity on hand | Stock value and/or Unit cost | Sales price
 *
 * What gets posted (dated as QuickBooks had them, in ONE database transaction):
 *   - one Journal entry on the opening date with every account balance except receivable / payable, balanced against
 *     "Opening Balance Equity";
 *   - one issued invoice per open QuickBooks invoice (Dr customer / Cr Opening Balance Equity);
 *   - one issued bill per open bill (Dr Opening Balance Equity / Cr vendor).
 * Opening Balance Equity nets to zero once all three are in. Runs only on a company with nothing in it, and only once.
 * plan() writes nothing: it checks the workbook and works out what import() would do.
 */
class QuickBooksImport
{
    public const FLAG = 'qb_import';

    protected int $dp = 2;

    protected array $book = [];

    public function __construct(protected XlsxReader $xlsx, protected ChartOfAccounts $chart, protected PartyService $parties, protected ItemService $items,
        protected SalesService $sales, protected PurchasesService $purchases) {}

    protected function db()
    {
        return DB::connection('tenant');
    }

    // ---- is it already done / is the company empty ----------------------------------------------

    public function done(): ?array
    {
        if (! Tables::has('app_flags')) {
            return null;
        }
        $v = $this->db()->table('app_flags')->where('name', self::FLAG)->value('value');

        return $v ? json_decode($v, true) : null;
    }

    /** What is already in this company that stops the import: label => count. */
    public function existingCounts(): array
    {
        $out = [];
        foreach (['entries' => ['journal entry', 'journal entries'], 'customers' => ['customer', 'customers'], 'vendors' => ['vendor', 'vendors'],
            'invoices' => ['invoice', 'invoices'], 'bills' => ['bill', 'bills']] as $table => $labels) {
            $n = Tables::has($table) ? $this->db()->table($table)->count() : 0;
            if ($n > 0) {
                $out[$labels[$n === 1 ? 0 : 1]] = $n;
            }
        }
        $used = $this->itemsInUse();
        if ($used > 0) {
            $out[$used === 1 ? 'item already used on a document' : 'items already used on documents'] = $used;
        }

        return $out;
    }

    /** Items on any quotation, invoice, bill, order, credit note, expense or stock adjustment. */
    protected function itemsInUse(): int
    {
        if (! Tables::has('items')) {
            return 0;
        }
        $ids = [];
        foreach (['quotation_items', 'invoice_items', 'bill_items', 'purchase_order_items', 'credit_note_items', 'stock_adjustment_items', 'expenses'] as $t) {
            if (Tables::has($t, 'item_id')) {
                $ids += array_flip($this->db()->table($t)->whereNotNull('item_id')->distinct()->pluck('item_id')->all());
            }
        }
        if (! $ids) {
            return 0;
        }

        return $this->db()->table('items')->whereIn('id', array_keys($ids))->count();
    }

    /** Items already here, keyed by lower-case name. */
    protected function existingItems(): array
    {
        $out = [];
        foreach ($this->db()->table('items')->get(['id', 'name', 'sku', 'type', 'quantity_on_hand', 'sales_price']) as $it) {
            $out[mb_strtolower(trim(preg_replace('/\s+/', ' ', $it->name)))] = (array) $it;
        }

        return $out;
    }

    // ---- reading the workbook --------------------------------------------------------------------

    /** Loads the workbook. Returns an error message, or true. */
    public function read(string $path): string|true
    {
        $this->dp = (int) (Setting::first()->decimal_places ?? 2);
        try {
            $this->book = $this->xlsx->read($path);
        } catch (Throwable $e) {
            return 'This file could not be opened as an Excel workbook (.xlsx). Save it from Excel as "Excel Workbook" and try again.';
        }

        return true;
    }

    /** A sheet as rows keyed by our field names, found by header text; null when the sheet is missing. */
    protected function sheet(string $title, array $columns, array &$errors): ?array
    {
        $rows = $this->book[strtolower($title)] ?? null;
        if ($rows === null) {
            return null;
        }
        $head = $rows[0] ?? [];
        $pos = [];
        foreach ($columns as $field => [$header, $required]) {
            $found = null;
            foreach ($head as $i => $h) {
                if (strtolower(trim((string) $h)) === strtolower($header)) {
                    $found = $i;
                    break;
                }
            }
            if ($found === null && $required) {
                $errors[] = "The \"$title\" sheet has no \"$header\" column.";
            }
            $pos[$field] = $found;
        }
        $out = [];
        for ($r = 1; $r < count($rows); $r++) {
            $row = ['_row' => $r + 1];
            $blank = true;
            foreach ($pos as $field => $i) {
                $v = $i !== null ? ($rows[$r][$i] ?? null) : null;
                if (is_string($v)) {
                    $v = trim($v);
                }
                if ($v !== null && $v !== '') {
                    $blank = false;
                }
                $row[$field] = $v;
            }
            if (! $blank) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** A money / quantity cell: null when empty, false when not a number; (12.50) means -12.50. */
    protected function num($v, ?int $dp = null): string|null|false
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_string($v)) {
            $v = str_replace([',', ' '], '', $v);
            if (preg_match('/^\((.*)\)$/', $v, $m)) {
                $v = '-'.$m[1];
            }
        }
        if (! is_numeric($v)) {
            return false;
        }

        return number_format(round((float) $v, $dp ?? $this->dp), $dp ?? $this->dp, '.', '');
    }

    /** A date cell (Excel serial, 2026-06-01 or 01/06/2026): Y-m-d, null or false. */
    protected function date($v): string|null|false
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            $serial = (float) $v;
            if ($serial < 1 || $serial > 2958465) {
                return false;
            }

            return gmdate('Y-m-d', (int) round(($serial - 25569) * 86400));
        }
        $v = (string) $v;
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $v, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return false;
    }

    protected function money(string $v): string
    {
        return (Money::cmp($v, '0') < 0 ? '-' : '').Money::format(Money::cmp($v, '0') < 0 ? Money::neg($v) : $v);
    }

    // ---- checking it and working out what would happen ------------------------------------------

    /**
     * errors (block the import), warnings, checks [label, QuickBooks figure, file figure, ok], plus everything import() needs.
     */
    public function plan(): array
    {
        $E = [];
        $W = [];
        $zero = number_format(0, $this->dp, '.', '');
        $P = ['errors' => [], 'warnings' => [], 'checks' => [], 'opening_date' => null, 'groups_new' => [], 'accounts' => [], 'lines' => [], 'customers' => [],
            'invoices' => [], 'vendors' => [], 'bills' => [], 'items' => [], 'totals' => []];

        foreach (['Start here', 'Accounts', 'Customers', 'Open invoices', 'Vendors', 'Open bills', 'Items'] as $s) {
            if (! isset($this->book[strtolower($s)])) {
                $E[] = "The workbook has no \"$s\" sheet. Use the opening balances workbook made for this import.";
            }
        }
        if ($E) {
            $P['errors'] = $E;

            return $P;
        }

        // opening date
        foreach ($this->book['start here'] as $r) {
            if (isset($r[0]) && strtolower(trim((string) $r[0])) === 'opening date' && isset($r[1]) && $r[1] !== '' && $r[1] !== null) {
                $P['opening_date'] = $this->date($r[1]);
                break;
            }
        }
        $od = $P['opening_date'];
        $set = Setting::first();
        if (! $od) {
            $E[] = 'Start here: "Opening date" is missing or not a date.';
        } else {
            $fs = substr((string) $set->fy_start, 0, 10);
            $fe = substr((string) $set->fy_end, 0, 10);
            if ($fs && $fe && ($od < $fs || $od > $fe)) {
                $E[] = 'The opening date ('.Money::date($od).') is outside this company\'s financial year ('.Money::date($fs).' to '.Money::date($fe).'). The year\'s income and expenses so far come in on the opening date, so it has to fall inside the financial year - change the year under Settings → Company.';
            }
            if ($od > date('Y-m-d')) {
                $E[] = 'The opening date is in the future.';
            }
            if ($lock = substr((string) $set->lock_date, 0, 10)) {
                if ($lock !== '0000-00-00' && $od <= $lock) {
                    $E[] = 'The books are closed through '.Money::date($lock).' (Settings → Close Books). Reopen them first.';
                }
            }
        }

        // customers + open invoices
        $custByKey = [];
        foreach ((array) $this->sheet('Customers', ['name' => ['Customer', true], 'phone' => ['Phone', false], 'email' => ['Email', false], 'address' => ['Address', false], 'balance' => ['Balance in QuickBooks', false]], $E) as $r) {
            $name = (string) $r['name'];
            if ($name === '') {
                $E[] = 'Customers row '.$r['_row'].': the name is empty.';

                continue;
            }
            $k = mb_strtolower($name);
            if (isset($custByKey[$k])) {
                $E[] = "Customers: \"$name\" is listed twice.";

                continue;
            }
            $bal = $this->num($r['balance']);
            if ($bal === false) {
                $E[] = 'Customers row '.$r['_row'].': the balance is not a number.';
                $bal = null;
            }
            $custByKey[$k] = count($P['customers']);
            $P['customers'][] = ['name' => mb_substr($name, 0, 255), 'phone' => mb_substr((string) $r['phone'], 0, 50), 'email' => mb_substr((string) $r['email'], 0, 255), 'address' => mb_substr((string) $r['address'], 0, 500), 'qb_balance' => $bal, 'owed' => $zero, 'count' => 0];
        }
        $numbers = [];
        $arTotal = $zero;
        foreach ((array) $this->sheet('Open invoices', ['customer' => ['Customer', true], 'number' => ['Nidaam invoice number', true], 'date' => ['Date', true], 'due' => ['Due date', false], 'amount' => ['Amount still owed', true], 'note' => ['Note', false]], $E) as $r) {
            $where = 'Open invoices row '.$r['_row'];
            $k = mb_strtolower((string) $r['customer']);
            if (! isset($custByKey[$k])) {
                $E[] = "$where: customer \"{$r['customer']}\" is not on the Customers sheet.";

                continue;
            }
            $num = is_numeric($r['number']) && (float) $r['number'] == (int) $r['number'] ? (int) $r['number'] : 0;
            if ($num <= 0) {
                $E[] = "$where: the invoice number must be a whole number above 0.";

                continue;
            }
            if (isset($numbers[$num])) {
                $E[] = "$where: invoice number $num is used twice.";

                continue;
            }
            $numbers[$num] = true;
            $date = $this->date($r['date']);
            $due = $this->date($r['due']);
            $amt = $this->num($r['amount']);
            if (! $date) {
                $E[] = "$where: the date is missing or not a date.";

                continue;
            }
            if ($due === false) {
                $E[] = "$where: the due date is not a date.";

                continue;
            }
            if ($amt === false || $amt === null || ! Money::positive($amt)) {
                $E[] = "$where: the amount still owed must be more than 0.";

                continue;
            }
            if ($od && $date > $od) {
                $E[] = "$where: dated after the opening date.";

                continue;
            }
            $ci = $custByKey[$k];
            $P['customers'][$ci]['owed'] = Money::add($P['customers'][$ci]['owed'], $amt);
            $P['customers'][$ci]['count']++;
            $arTotal = Money::add($arTotal, $amt);
            $P['invoices'][] = ['c' => $ci, 'number' => $num, 'date' => $date, 'due' => $due, 'amount' => $amt, 'note' => mb_substr((string) $r['note'], 0, 400)];
        }
        foreach ($P['customers'] as $c) {
            if ($c['qb_balance'] !== null && Money::cmp($c['qb_balance'], $c['owed']) !== 0) {
                $E[] = "Customer \"{$c['name']}\": QuickBooks balance ".$this->money($c['qb_balance']).' but the open invoices add up to '.$this->money($c['owed']).'.';
            }
        }

        // vendors + open bills
        $vendByKey = [];
        foreach ((array) $this->sheet('Vendors', ['name' => ['Vendor', true], 'phone' => ['Phone', false], 'email' => ['Email', false], 'address' => ['Address', false], 'balance' => ['Balance in QuickBooks', false]], $E) as $r) {
            $name = (string) $r['name'];
            if ($name === '') {
                $E[] = 'Vendors row '.$r['_row'].': the name is empty.';

                continue;
            }
            $k = mb_strtolower($name);
            if (isset($vendByKey[$k])) {
                $E[] = "Vendors: \"$name\" is listed twice.";

                continue;
            }
            $bal = $this->num($r['balance']);
            if ($bal === false) {
                $E[] = 'Vendors row '.$r['_row'].': the balance is not a number.';
                $bal = null;
            }
            $vendByKey[$k] = count($P['vendors']);
            $P['vendors'][] = ['name' => mb_substr($name, 0, 255), 'phone' => mb_substr((string) $r['phone'], 0, 50), 'email' => mb_substr((string) $r['email'], 0, 255), 'address' => mb_substr((string) $r['address'], 0, 500), 'qb_balance' => $bal, 'owed' => $zero, 'count' => 0];
        }
        $apTotal = $zero;
        foreach ((array) $this->sheet('Open bills', ['vendor' => ['Vendor', true], 'ref' => ['Vendor reference', false], 'date' => ['Date', true], 'due' => ['Due date', false], 'amount' => ['Amount still owed', true], 'note' => ['Note', false]], $E) as $r) {
            $where = 'Open bills row '.$r['_row'];
            $k = mb_strtolower((string) $r['vendor']);
            if (! isset($vendByKey[$k])) {
                $E[] = "$where: vendor \"{$r['vendor']}\" is not on the Vendors sheet.";

                continue;
            }
            $date = $this->date($r['date']);
            $due = $this->date($r['due']);
            $amt = $this->num($r['amount']);
            if (! $date) {
                $E[] = "$where: the date is missing or not a date.";

                continue;
            }
            if ($due === false) {
                $E[] = "$where: the due date is not a date.";

                continue;
            }
            if ($amt === false || $amt === null || ! Money::positive($amt)) {
                $E[] = "$where: the amount still owed must be more than 0.";

                continue;
            }
            if ($od && $date > $od) {
                $E[] = "$where: dated after the opening date.";

                continue;
            }
            $vi = $vendByKey[$k];
            $P['vendors'][$vi]['owed'] = Money::add($P['vendors'][$vi]['owed'], $amt);
            $P['vendors'][$vi]['count']++;
            $apTotal = Money::add($apTotal, $amt);
            $P['bills'][] = ['v' => $vi, 'ref' => mb_substr((string) $r['ref'], 0, 100), 'date' => $date, 'due' => $due, 'amount' => $amt, 'note' => mb_substr((string) $r['note'], 0, 400)];
        }
        foreach ($P['vendors'] as $v) {
            if ($v['qb_balance'] !== null && Money::cmp($v['qb_balance'], $v['owed']) !== 0) {
                $E[] = "Vendor \"{$v['name']}\": QuickBooks balance ".$this->money($v['qb_balance']).' but the open bills add up to '.$this->money($v['owed']).'.';
            }
        }

        // items: each needs its Stock value (QuickBooks "Asset Value") or Unit cost ("Avg Cost"); settled below against the Trial Balance's Inventory figure
        $itemRows = $this->sheet('Items', ['name' => ['Item', true], 'qty' => ['Quantity on hand', true], 'value' => ['Stock value', false], 'cost' => ['Unit cost', false], 'price' => ['Sales price', false]], $E);
        if (is_array($itemRows) && isset($this->book['items'][0])) {
            $heads = array_map(fn ($h) => strtolower(trim((string) $h)), $this->book['items'][0]);
            if (! in_array('stock value', $heads, true) && ! in_array('unit cost', $heads, true)) {
                $E[] = 'The "Items" sheet needs a "Unit cost" or a "Stock value" column.';
            }
        }
        $itemKeys = [];
        $have = $this->existingItems();
        $matched = [];
        $missingCost = [];
        foreach ((array) $itemRows as $r) {
            $where = 'Items row '.$r['_row'];
            $name = (string) $r['name'];
            if ($name === '' || strtolower($name) === 'total') {
                continue;
            }
            $k = mb_strtolower($name);
            if (isset($itemKeys[$k])) {
                $E[] = "Items: \"$name\" is listed twice.";

                continue;
            }
            $itemKeys[$k] = true;
            $qty = $this->num($r['qty'], 2);
            $value = $this->num($r['value']);
            $ucost = $this->num($r['cost']);
            $price = $this->num($r['price']);
            if ($qty === null || $qty === false || (float) $qty < 0) {
                $E[] = "$where ($name): the quantity on hand must be a number, 0 or more.";

                continue;
            }
            if ($value === false || ($value !== null && (float) $value < 0)) {
                $E[] = "$where ($name): the stock value is not a number.";

                continue;
            }
            if ($ucost === false || ($ucost !== null && (float) $ucost < 0)) {
                $E[] = "$where ($name): the unit cost is not a number.";

                continue;
            }
            if ($price === false) {
                $E[] = "$where ($name): the sales price is not a number.";

                continue;
            }
            $hasValue = $value !== null && (float) $value > 0;
            $hasCost = $ucost !== null && (float) $ucost > 0;
            if ((float) $qty > 0 && ! $hasValue && ! $hasCost) {
                $missingCost[] = $name;
            }
            if ((float) $qty == 0 && $hasValue && ! $hasCost) {
                $E[] = "$where ($name): has a stock value but no quantity.";

                continue;
            }
            // already typed in? same name (spaces and capitals ignored) or, for a name QuickBooks cut short with "...", the one item starting the same way
            $nk = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));
            $ex = $have[$nk] ?? null;
            if (! $ex && str_ends_with($nk, '...')) {
                $pre = rtrim(substr($nk, 0, -3));
                $cands = [];
                foreach ($have as $hk => $h) {
                    if ($pre !== '' && str_starts_with($hk, $pre)) {
                        $cands[] = $h;
                    }
                }
                if (count($cands) === 1) {
                    $ex = $cands[0];
                }
            }
            if ($ex && isset($matched[(int) $ex['id']])) {
                $E[] = "$where ($name): matches the same item as another row.";

                continue;
            }
            if ($ex) {
                $matched[(int) $ex['id']] = true;
                if ($ex['type'] !== 'inventory') {
                    $E[] = 'Item "'.$ex['name'].'" is here as a '.str_replace('_', '-', $ex['type']).' item, not an Inventory item - change its type under Items first.';
                } elseif (Money::cmp(number_format((float) $ex['quantity_on_hand'], 2, '.', ''), $qty) !== 0) {
                    $W[] = 'Item "'.$ex['name'].'": '.(float) $ex['quantity_on_hand'].' on hand now, the workbook says '.(float) $qty.' - the workbook figure is used.';
                }
            }
            $P['items'][] = ['name' => $ex ? $ex['name'] : mb_substr($name, 0, 255), 'qty' => $qty, 'v' => $hasValue ? $value : null, 'c' => $hasCost ? $ucost : null, 'cost' => $zero, 'value' => $zero,
                'price' => $price === null ? $zero : $price, 'existing_id' => $ex ? (int) $ex['id'] : null];
        }
        foreach ($have as $h) {
            if (isset($matched[(int) $h['id']]) || $h['type'] !== 'inventory') {
                continue;
            }
            if ((float) $h['quantity_on_hand'] != 0) {
                $E[] = 'Item "'.$h['name'].'" is here with '.(float) $h['quantity_on_hand'].' on hand but not in the workbook - add it to the Items sheet (with its stock value) so the stock and the Inventory account agree.';
            } else {
                $W[] = 'Item "'.$h['name'].'" is here but not in the workbook - it stays as it is, with nothing on hand.';
            }
        }
        if ($missingCost) {
            $E[] = 'Items: fill in the unit cost for '.count($missingCost).' item'.(count($missingCost) === 1 ? '' : 's').' with stock on hand ('.implode(', ', array_slice($missingCost, 0, 5)).(count($missingCost) > 5 ? ', ...' : '').'). QuickBooks: Reports → Inventory → Inventory Valuation Summary, column Avg Cost (or its Asset Value in the Stock value column).';
        }

        // accounts
        $accRows = $this->sheet('Accounts', ['qb' => ['QuickBooks account', true], 'dr' => ['Debit', true], 'cr' => ['Credit', true], 'target' => ['Nidaam account', true], 'group' => ['Nidaam group', true], 'under' => ['New group goes under', false], 'bank' => ['Bank or cash?', false]], $E);
        $groups = Group::get(['id', 'parent_id', 'name', 'code', 'affects_gross'])->map(fn ($g) => $g->toArray())->all();
        $groupByName = [];
        foreach ($groups as $g) {
            $groupByName[mb_strtolower(trim($g['name']))] = $g;
        }
        $ledgerByName = [];
        foreach (Ledger::get(['id', 'name', 'group_id']) as $l) {
            $ledgerByName[mb_strtolower(trim($l->name))] = $l->toArray();
        }
        $drTotal = $zero;
        $crTotal = $zero;
        $special = ['(customers)' => $zero, '(vendors)' => $zero, '(items)' => $zero];
        $specialSeen = [];
        $targets = [];
        foreach ((array) $accRows as $r) {
            $qb = (string) $r['qb'];
            if ($qb === '' || strtolower($qb) === 'total') {
                continue;
            }
            $where = 'Accounts row '.$r['_row']." ($qb)";
            $dr = $this->num($r['dr']);
            $cr = $this->num($r['cr']);
            if ($dr === false || $cr === false) {
                $E[] = "$where: debit / credit is not a number.";

                continue;
            }
            $dr ??= $zero;
            $cr ??= $zero;
            $drTotal = Money::add($drTotal, $dr);
            $crTotal = Money::add($crTotal, $cr);
            $net = Money::sub($dr, $cr); // + debit, - credit
            $target = trim((string) $r['target']);
            $tk = mb_strtolower($target);
            if ($target === '') {
                if (! Money::isZero($net)) {
                    $E[] = "$where: has a balance of ".$this->money($net).' but no account to go to.';
                }

                continue;
            }
            if (isset($special[$tk])) {
                $special[$tk] = Money::add($special[$tk], $net);
                $specialSeen[$tk] = true;

                continue;
            }
            if (! isset($targets[$tk])) {
                $acc = ['name' => mb_substr($target, 0, 255), 'qb' => [], 'net' => $zero, 'ledger_id' => null, 'group' => null, 'group_id' => null, 'group_new' => null, 'bank' => 0, 'dc' => null];
                if (isset($ledgerByName[$tk])) {
                    $acc['ledger_id'] = (int) $ledgerByName[$tk]['id'];
                    $acc['group'] = $this->groupName($groups, $ledgerByName[$tk]['group_id']);
                } else {
                    $gname = trim((string) $r['group']);
                    $gk = mb_strtolower($gname);
                    if ($gname === '') {
                        $E[] = "$where: \"$target\" is a new account - say which group it goes in.";

                        continue;
                    }
                    if (isset($groupByName[$gk])) {
                        $acc['group'] = $groupByName[$gk]['name'];
                        $acc['group_id'] = (int) $groupByName[$gk]['id'];
                        $acc['dc'] = $this->sideForGroup($groups, $groupByName[$gk]['id']);
                    } else {
                        $under = trim((string) $r['under']);
                        $uk = mb_strtolower($under);
                        if ($under === '' || ! isset($groupByName[$uk])) {
                            $E[] = "$where: there is no group called \"$gname\"".($under !== '' ? " and no group \"$under\" to put it under" : '').'.';

                            continue;
                        }
                        $P['groups_new'][$gk] ??= ['name' => mb_substr($gname, 0, 255), 'parent_id' => (int) $groupByName[$uk]['id'], 'parent' => $groupByName[$uk]['name']];
                        $acc['group'] = $gname;
                        $acc['group_new'] = $gk;
                        $acc['dc'] = $this->sideForGroup($groups, $groupByName[$uk]['id']);
                    }
                    $acc['bank'] = preg_match('/^(y|yes|1|true)$/i', trim((string) $r['bank'])) ? 1 : 0;
                }
                $targets[$tk] = count($P['accounts']);
                $P['accounts'][] = $acc;
            }
            $i = $targets[$tk];
            $P['accounts'][$i]['qb'][] = $qb;
            $P['accounts'][$i]['net'] = Money::add($P['accounts'][$i]['net'], $net);
        }

        // a new account named like a customer or vendor would make two ledgers with one name
        foreach ($P['accounts'] as $a) {
            if (! $a['ledger_id'] && (isset($custByKey[mb_strtolower($a['name'])]) || isset($vendByKey[mb_strtolower($a['name'])]))) {
                $E[] = "Account \"{$a['name']}\" has the same name as a customer or vendor - rename one of them in the workbook.";
            }
        }
        foreach ($P['customers'] as $c) {
            $k = mb_strtolower($c['name']);
            if (isset($vendByKey[$k])) {
                $W[] = "\"{$c['name']}\" is both a customer and a vendor - they are kept as two separate accounts.";
            }
            if (isset($ledgerByName[$k])) {
                $W[] = "Customer \"{$c['name']}\" has the same name as the account \"{$ledgerByName[$k]['name']}\" (".$this->groupName($groups, $ledgerByName[$k]['group_id']).'). Both are kept - the customer gets its own account under Accounts Receivable.';
            }
        }
        foreach ($P['vendors'] as $v) {
            $k = mb_strtolower($v['name']);
            if (isset($ledgerByName[$k])) {
                $W[] = "Vendor \"{$v['name']}\" has the same name as the account \"{$ledgerByName[$k]['name']}\" (".$this->groupName($groups, $ledgerByName[$k]['group_id']).'). Both are kept - the vendor gets its own account under Accounts Payable.';
            }
        }
        foreach (['(customers)' => 'Accounts Receivable', '(vendors)' => 'Accounts Payable', '(items)' => 'Inventory'] as $tk => $label) {
            if (empty($specialSeen[$tk])) {
                $E[] = "Accounts: no row has Nidaam account \"$tk\" (the QuickBooks $label line).";
            }
        }

        // the checks
        $apTb = Money::neg($special['(vendors)']);
        $invTb = $special['(items)'];
        [$stockTotal, $invOk, $itemNote] = $this->settleItems($P['items'], $invTb, ! $missingCost, $zero);
        $P['checks'] = [
            ['Trial Balance: debits = credits', $drTotal, $crTotal, Money::cmp($drTotal, $crTotal) === 0],
            ['Accounts Receivable = open invoices', $special['(customers)'], $arTotal, Money::cmp($special['(customers)'], $arTotal) === 0],
            ['Accounts Payable = open bills', $apTb, $apTotal, Money::cmp($apTb, $apTotal) === 0],
            ['Inventory Asset = the items\' stock', $invTb, $stockTotal, $invOk],
        ];
        if (Money::cmp($drTotal, $crTotal) !== 0) {
            $E[] = 'Accounts: debits ('.$this->money($drTotal).') and credits ('.$this->money($crTotal).') don\'t balance.';
        }
        if (Money::cmp($special['(customers)'], $arTotal) !== 0) {
            $E[] = 'Accounts Receivable in the Trial Balance is '.$this->money($special['(customers)']).' but the open invoices add up to '.$this->money($arTotal).'.';
        }
        if (Money::cmp($apTb, $apTotal) !== 0) {
            $E[] = 'Accounts Payable in the Trial Balance is '.$this->money($apTb).' but the open bills add up to '.$this->money($apTotal).'.';
        }
        if (! $missingCost && ! $invOk) {
            $E[] = $itemNote;
        } elseif ($itemNote) {
            $W[] = $itemNote;
        }

        // the opening journal
        foreach ($P['accounts'] as $i => $a) {
            if (! Money::isZero($a['net'])) {
                $P['lines'][] = ['acc' => $i, 'amount' => $a['net']];
            }
        }
        if (! Money::isZero($invTb)) {
            $P['lines'][] = ['acc' => '(items)', 'amount' => $invTb];
        }
        $P['totals'] = ['ar' => $arTotal, 'ap' => $apTotal, 'stock' => $invTb, 'dr' => $drTotal, 'cr' => $crTotal];

        // the company must be empty
        if ($this->done()) {
            $E[] = 'QuickBooks balances have already been brought into this company.';
        } elseif ($exists = $this->existingCounts()) {
            $bits = [];
            foreach ($exists as $label => $n) {
                $bits[] = "$n $label";
            }
            $E[] = 'This company already has '.implode(', ', $bits).'. The QuickBooks move only goes into a new, empty company - create one under Admin → Companies (and keep this one as it is).';
        }
        $P['errors'] = $E;
        $P['warnings'] = $W;

        return $P;
    }

    /**
     * Works out each item's stock value and unit cost and whether they agree with the Trial Balance's Inventory figure.
     * 1. read as stock values (or quantity x unit cost where only a cost was given): must match to the cent;
     * 2. else read as unit costs (QuickBooks "Avg Cost", 2 decimals): up to half a cent per unit is accepted - the Inventory
     *    account still comes in at exactly the Trial Balance figure.
     *
     * @return array{0: string, 1: bool, 2: ?string} stock total, ok, message
     */
    protected function settleItems(array &$items, string $invTb, bool $complete, string $zero): array
    {
        $dp = $this->dp;
        $fmt = fn ($x) => number_format(round($x, $dp), $dp, '.', '');
        $byValue = $zero;
        foreach ($items as $it) {
            $v = $it['v'] ?? ($it['c'] !== null ? $fmt((float) $it['qty'] * (float) $it['c']) : $zero);
            $byValue = Money::add($byValue, $v);
        }
        $useValues = Money::cmp($byValue, $invTb) === 0;
        $byCost = $zero;
        $slack = 0.0;
        foreach ($items as $it) {
            if ($it['c'] !== null) {
                $byCost = Money::add($byCost, $fmt((float) $it['qty'] * round((float) $it['c'], $dp)));
                $slack += (float) $it['qty'] * 0.5 * 10 ** -$dp + 10 ** -$dp;
            } elseif ($it['v'] !== null) {
                $byCost = Money::add($byCost, $it['v']);
            }
        }
        $costDiff = Money::sub($invTb, $byCost);
        $useCosts = ! $useValues && abs((float) $costDiff) <= $slack + 0.000001;

        foreach ($items as &$it) {
            $q = (float) $it['qty'];
            if ($useValues || ! $useCosts) {
                $value = $it['v'] ?? ($it['c'] !== null ? $fmt($q * (float) $it['c']) : $zero);
                $cost = $q > 0 ? $fmt((float) $value / $q) : ($it['c'] !== null ? $fmt((float) $it['c']) : $zero);
            } else {
                $cost = $it['c'] !== null ? $fmt((float) $it['c']) : ($q > 0 && $it['v'] !== null ? $fmt((float) $it['v'] / $q) : $zero);
                $value = $it['c'] !== null ? $fmt($q * (float) $cost) : ($it['v'] ?? $zero);
            }
            $it['cost'] = $cost;
            $it['value'] = $value;
        }
        unset($it);

        if (! $complete) {
            return [$byValue, false, null];
        }
        if ($useValues) {
            $atCost = $zero;
            foreach ($items as $it) {
                $atCost = Money::add($atCost, Money::mul($it['qty'], $it['cost']));
            }
            $note = Money::cmp($atCost, $byValue) === 0 ? null : "The cost of one unit is kept to $dp decimals, so quantity x unit cost comes to ".$this->money($atCost).', '.$this->money(Money::cmp($atCost, $byValue) < 0 ? Money::sub($byValue, $atCost) : Money::sub($atCost, $byValue)).' '.(Money::cmp($atCost, $byValue) < 0 ? 'less than' : 'more than').' the stock value. The Inventory account still comes in at exactly '.$this->money($invTb).'; only the cost of future sales is affected, by a fraction of a cent per unit.';

            return [$byValue, true, $note];
        }
        if ($useCosts) {
            $note = Money::isZero($costDiff) ? null : 'The unit costs were read as QuickBooks "Avg Cost". Quantity x unit cost comes to '.$this->money($byCost).', '.$this->money($costDiff).' from QuickBooks\' '.$this->money($invTb).' - that is only the rounding of the costs to cents. The Inventory account comes in at exactly '.$this->money($invTb).'.';

            return [$byCost, true, $note];
        }

        return [$byValue, false, 'Inventory Asset in the Trial Balance is '.$this->money($invTb).', but the items come to '.$this->money($byValue).' as stock values or '.$this->money($byCost).' as quantity x unit cost - too far apart for rounding. Check the Items sheet against the QuickBooks Inventory Valuation Summary (Avg Cost goes in Unit cost; Asset Value goes in Stock value).'];
    }

    protected function groupName(array $groups, $id): string
    {
        foreach ($groups as $g) {
            if ((int) $g['id'] === (int) $id) {
                return $g['name'];
            }
        }

        return '';
    }

    /** D for assets and expenses, C for liabilities / equity / income - by the top group. */
    protected function sideForGroup(array $groups, $id): string
    {
        $byId = [];
        foreach ($groups as $g) {
            $byId[(int) $g['id']] = $g;
        }
        $g = $byId[(int) $id] ?? null;
        $guard = 0;
        while ($g && ! empty($g['parent_id']) && isset($byId[(int) $g['parent_id']]) && $guard++ < 20) {
            $g = $byId[(int) $g['parent_id']];
        }
        if (! $g) {
            return 'D';
        }
        $code = (string) $g['code'];

        return in_array((int) $g['id'], [1, 4], true) || str_starts_with($code, '01') || str_starts_with($code, '04') ? 'D' : 'C';
    }

    // ---- doing it -------------------------------------------------------------------------------

    /** Brings everything in. $P must come straight from plan() with no errors. @return array{ok: bool, error?: string, summary?: array} */
    public function import(array $P): array
    {
        if (! empty($P['errors'])) {
            return ['ok' => false, 'error' => 'The workbook still has problems - fix them first.'];
        }
        if (Setting::first()->account_locked == 1) {
            return ['ok' => false, 'error' => 'This company is locked. Unlock it under Settings → Lock company first.'];
        }
        // tables first: DDL commits any open transaction, so none of it may happen inside the one below
        if (! Schema::connection('tenant')->hasTable('app_flags')) {
            Schema::connection('tenant')->create('app_flags', function ($t) {
                $t->string('name', 64)->primary();
                $t->string('value', 255)->nullable();
            });
        }
        if (! empty($P['items'])) {
            $this->items->ensureInventoryAccounts();
        }

        $sales = $this->sales->settings();
        $purch = $this->purchases->settings();
        $itemSet = $this->items->settings();
        $journalType = $this->db()->table('entrytypes')->where('label', 'journal')->first();
        if (! $sales || empty($sales->ar_group_id) || empty($sales->sales_ledger_id) || empty($sales->entrytype_id)) {
            return ['ok' => false, 'error' => 'Sales settings are not complete (customers group, sales account, entry type). Open Sales → Settings, save, and try again.'];
        }
        if (! $purch || empty($purch->ap_group_id) || empty($purch->entrytype_id)) {
            return ['ok' => false, 'error' => 'Purchase settings are not complete (vendors group, entry type). Open Purchases → Settings, save, and try again.'];
        }
        if (! $journalType) {
            return ['ok' => false, 'error' => 'There is no "Journal" entry type (Settings → Entry types).'];
        }
        if ($this->hasItemsLine($P) && empty($itemSet->inventory_asset_ledger_id)) {
            return ['ok' => false, 'error' => 'There is no Inventory Asset account set under Items → Settings.'];
        }

        // one import at a time per company (double click, two tabs): whoever gets the lock checks again that nothing is in yet
        $lockName = 'nidaam_qb_import_'.config('database.connections.tenant.database');
        $got = $this->db()->selectOne('SELECT GET_LOCK(?, 0) AS l', [$lockName]);
        if (! $got || (int) $got->l !== 1) {
            return ['ok' => false, 'error' => 'The import is already running - wait a moment and reload the page.'];
        }
        try {
            if ($this->done() || $this->existingCounts()) {
                return ['ok' => false, 'error' => 'This company is no longer empty, so nothing was brought in.'];
            }

            return $this->importLocked($P, $sales, $purch, $itemSet, (int) $journalType->id);
        } finally {
            $this->db()->select('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    protected function importLocked(array $P, object $sales, object $purch, object $itemSet, int $journalType): array
    {
        $this->db()->beginTransaction();
        try {
            $res = $this->write($P, $sales, $purch, $itemSet, $journalType);
        } catch (Throwable $e) {
            $this->db()->rollBack();

            return ['ok' => false, 'error' => 'Nothing was saved - something went wrong part way ('.($e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage()).').'];
        }
        if (! $res['ok']) {
            $this->db()->rollBack();

            return ['ok' => false, 'error' => 'Nothing was saved - '.($res['error'] ?? 'the database refused one of the records.')];
        }
        $this->db()->commit();
        Money::forget();
        ActivityLog::add('Brought in QuickBooks balances as at '.$P['opening_date'].': '.count($P['customers']).' customers, '.count($P['invoices']).' open invoices, '.count($P['vendors']).' vendors, '.count($P['bills']).' open bills, '.count($P['items']).' items');

        return $res;
    }

    protected function hasItemsLine(array $P): bool
    {
        foreach ($P['lines'] as $l) {
            if ($l['acc'] === '(items)') {
                return true;
            }
        }

        return false;
    }

    protected function newLedger(int $groupId, string $name, string $dc, int $bank, string $notes): int
    {
        return (int) Ledger::create(['group_id' => $groupId, 'name' => $name, 'code' => $this->chart->nextLedgerCode($groupId), 'op_balance' => '0.00', 'op_balance_dc' => $dc,
            'type' => $bank ? 1 : 0, 'reconciliation' => 0, 'notes' => $notes])->id;
    }

    /** One balanced entry; lines are [ledger id, amount, D|C, narration]. */
    protected function entry(int $typeId, string $date, string $notes, array $lines): int
    {
        $dr = '0';
        $cr = '0';
        foreach ($lines as $l) {
            $l[2] === 'D' ? $dr = Money::add($dr, $l[1]) : $cr = Money::add($cr, $l[1]);
        }
        if (Money::cmp($dr, $cr) !== 0) {
            throw new \RuntimeException("an entry did not balance ($notes)");
        }
        $id = $this->db()->table('entries')->insertGetId(['entrytype_id' => $typeId, 'tag_id' => null, 'number' => ((int) $this->db()->table('entries')->where('entrytype_id', $typeId)->max('number')) + 1,
            'date' => $date, 'dr_total' => $dr, 'cr_total' => $cr, 'notes' => mb_substr($notes, 0, 500)]);
        foreach ($lines as $l) {
            $this->db()->table('entryitems')->insert(['entry_id' => $id, 'ledger_id' => $l[0], 'amount' => $l[1], 'dc' => $l[2], 'narration' => mb_substr($l[3], 0, 500)]);
        }

        return $id;
    }

    protected function write(array $P, object $sales, object $purch, object $itemSet, int $journalType): array
    {
        $now = now();
        $od = $P['opening_date'];
        $src = 'QuickBooks (as at '.Money::date($od).')';

        // Opening Balance Equity: the other side of every opening line
        $obe = $this->db()->table('ledgers')->where('name', 'Opening Balance Equity')->first();
        $newGroupIds = [];
        foreach ($P['groups_new'] as $gk => $g) {
            $parent = Group::find($g['parent_id']);
            $newGroupIds[$gk] = (int) Group::create(['parent_id' => $g['parent_id'], 'name' => $g['name'], 'code' => $this->chart->nextGroupCode((int) $g['parent_id']), 'affects_gross' => $parent ? (int) $parent->affects_gross : 0])->id;
        }
        $ledgerIds = [];
        foreach ($P['accounts'] as $i => $a) {
            if ($a['ledger_id']) {
                $ledgerIds[$i] = $a['ledger_id'];

                continue;
            }
            $gid = $a['group_new'] !== null ? $newGroupIds[$a['group_new']] : $a['group_id'];
            $ledgerIds[$i] = $this->newLedger($gid, $a['name'], $a['dc'] ?: 'D', (int) $a['bank'], 'From '.implode(', ', $a['qb']).' in QuickBooks');
            if (! $obe && strtolower($a['name']) === 'opening balance equity') {
                $obe = (object) ['id' => $ledgerIds[$i]];
            }
        }
        if (! $obe) {
            $eq = Group::where('name', 'Equity')->first() ?? Group::whereNull('parent_id')->where('code', 'like', '02%')->first();
            if (! $eq) {
                return ['ok' => false, 'error' => 'there is no Equity group to put "Opening Balance Equity" in.'];
            }
            $obe = (object) ['id' => $this->newLedger((int) $eq->id, 'Opening Balance Equity', 'C', 0, 'The other side of the balances brought in from QuickBooks - comes back to zero')];
        }
        $obeId = (int) $obe->id;

        // customers and vendors: made the way the app always makes them, each with its own account
        $custIds = $custLedger = $vendIds = $vendLedger = [];
        foreach ($P['customers'] as $i => $c) {
            $cust = $this->parties->create('ar', ['name' => $c['name'], 'contact_person' => '', 'phone' => $c['phone'], 'email' => $c['email'], 'address' => $c['address'], 'notes' => 'Brought in from QuickBooks']);
            $custIds[$i] = (int) $cust->id;
            $custLedger[$i] = (int) $cust->ledger_id;
        }
        foreach ($P['vendors'] as $i => $v) {
            $vend = $this->parties->create('ap', ['name' => $v['name'], 'contact_person' => '', 'phone' => $v['phone'], 'email' => $v['email'], 'address' => $v['address'], 'notes' => 'Brought in from QuickBooks', 'expense_ledger_id' => null]);
            $vendIds[$i] = (int) $vend->id;
            $vendLedger[$i] = (int) $vend->ledger_id;
        }

        // items: quantity and cost are set here; the Inventory account gets its figure in the journal
        foreach ($P['items'] as $it) {
            if (! empty($it['existing_id'])) {
                $upd = ['quantity_on_hand' => $it['qty'], 'purchase_cost' => $it['cost']];
                if (Money::positive($it['price'])) {
                    $upd['sales_price'] = $it['price'];
                }
                $this->db()->table('items')->where('id', $it['existing_id'])->update($upd);

                continue;
            }
            $this->items->create(['type' => 'inventory', 'category_id' => null, 'sku' => null, 'name' => $it['name'], 'description' => '', 'sales_price' => $it['price'],
                'sales_ledger_id' => $sales->sales_ledger_id, 'expense_ledger_id' => null, 'purchase_cost' => $it['cost'], 'quantity_on_hand' => $it['qty']]);
        }

        // the opening journal
        $lines = [];
        $net = '0';
        foreach ($P['lines'] as $l) {
            if ($l['acc'] === '(items)') {
                $lid = (int) $itemSet->inventory_asset_ledger_id;
                $label = 'Stock on hand from '.$src;
            } else {
                $lid = $ledgerIds[$l['acc']];
                $label = 'From '.implode(', ', $P['accounts'][$l['acc']]['qb']).' in '.$src;
            }
            $neg = Money::cmp($l['amount'], '0') < 0;
            $lines[] = [$lid, $neg ? Money::neg($l['amount']) : $l['amount'], $neg ? 'C' : 'D', $label];
            $net = Money::add($net, $l['amount']);
        }
        // $net = receivable - payable (the two left out): Opening Balance Equity takes the opposite and gets it back from the invoices and bills
        if (! Money::isZero($net)) {
            $neg = Money::cmp($net, '0') < 0;
            $lines[] = [$obeId, $neg ? Money::neg($net) : $net, $neg ? 'D' : 'C', 'Balancing figure - comes back to zero once the open invoices and bills are in'];
        }
        $journalId = $lines ? $this->entry($journalType, $od, 'Opening balances brought in from '.$src, $lines) : null;

        // open invoices
        $desc = 'Balance brought forward from QuickBooks';
        foreach ($P['invoices'] as $inv) {
            $c = $P['customers'][$inv['c']];
            $eid = $this->entry((int) $sales->entrytype_id, $inv['date'], 'Sales Invoice #'.$inv['number'].' - '.$c['name'].' (brought in from QuickBooks)', [
                [$custLedger[$inv['c']], $inv['amount'], 'D', 'Sales Invoice #'.$inv['number']],
                [$obeId, $inv['amount'], 'C', 'Sales Invoice #'.$inv['number'].' - '.$desc],
            ]);
            $iid = $this->db()->table('invoices')->insertGetId(['customer_id' => $custIds[$inv['c']], 'entry_id' => $eid, 'number' => $inv['number'], 'date' => $inv['date'], 'due_date' => $inv['due'],
                'status' => 'issued', 'notes' => $inv['note'] !== '' ? $inv['note'] : null, 'subtotal' => $inv['amount'], 'tax_total' => '0.00', 'total' => $inv['amount'], 'created_at' => $now]);
            $this->db()->table('invoice_items')->insert(['invoice_id' => $iid, 'description' => $desc.($inv['note'] !== '' ? ' - '.$inv['note'] : ''), 'qty' => '1.00', 'unit_price' => $inv['amount'], 'amount' => $inv['amount'], 'sort_order' => 0]);
        }

        // open bills, numbered on from this company's bill numbering (1 in a new company)
        $billNo = $this->purchases->nextNumber('bills');
        foreach ($P['bills'] as $b) {
            $v = $P['vendors'][$b['v']];
            $eid = $this->entry((int) $purch->entrytype_id, $b['date'], 'Bill #'.$billNo.' - '.$v['name'].' (brought in from QuickBooks)', [
                [$obeId, $b['amount'], 'D', 'Bill #'.$billNo.' - '.$desc],
                [$vendLedger[$b['v']], $b['amount'], 'C', 'Bill #'.$billNo],
            ]);
            $bid = $this->db()->table('bills')->insertGetId(['vendor_id' => $vendIds[$b['v']], 'entry_id' => $eid, 'number' => $billNo, 'vendor_ref' => $b['ref'] !== '' ? $b['ref'] : null, 'date' => $b['date'], 'due_date' => $b['due'],
                'status' => 'issued', 'notes' => $b['note'] !== '' ? $b['note'] : null, 'subtotal' => $b['amount'], 'tax_total' => '0.00', 'total' => $b['amount'], 'created_at' => $now]);
            $this->db()->table('bill_items')->insert(['bill_id' => $bid, 'ledger_id' => $obeId, 'description' => $desc.($b['note'] !== '' ? ' - '.$b['note'] : ''), 'qty' => '1.00', 'unit_price' => $b['amount'], 'amount' => $b['amount'], 'sort_order' => 0]);
            $billNo++;
        }

        // Opening Balance Equity must be back at zero
        $sum = $this->db()->table('entryitems')->where('ledger_id', $obeId)->selectRaw("COALESCE(SUM(CASE WHEN dc = 'D' THEN amount ELSE -amount END), 0) AS n")->value('n');
        if (Money::cmp(number_format((float) $sum, $this->dp, '.', ''), '0') !== 0) {
            return ['ok' => false, 'error' => "Opening Balance Equity did not come back to zero ($sum)."];
        }

        $summary = ['at' => $now->format('Y-m-d H:i:s'), 'as_of' => $od, 'accounts' => count($P['accounts']), 'customers' => count($P['customers']), 'invoices' => count($P['invoices']),
            'vendors' => count($P['vendors']), 'bills' => count($P['bills']), 'items' => count($P['items']), 'ar' => $P['totals']['ar'], 'ap' => $P['totals']['ap'], 'stock' => $P['totals']['stock'], 'journal' => $journalId];
        $this->db()->statement('INSERT INTO app_flags (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [self::FLAG, json_encode($summary)]);

        return ['ok' => true, 'summary' => $summary];
    }
}
