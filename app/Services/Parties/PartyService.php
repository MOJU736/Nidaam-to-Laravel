<?php

namespace App\Services\Parties;

use App\Models\Tenant\Bill;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerVehicle;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Ledger;
use App\Models\Tenant\PurchaseSetting;
use App\Models\Tenant\SalesSetting;
use App\Models\Tenant\Vendor;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\LedgerBalances;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customers (accounts receivable, side 'ar') and vendors (accounts payable, side 'ap').
 * They are the same idea mirrored: a party row plus its own subsidiary ledger, so one
 * service handles both. Ported from Sales_model / Purchases_model.
 */
class PartyService
{
    public function __construct(protected LedgerBalances $balances, protected ChartOfAccounts $chart) {}

    /** Everything that differs between the two sides. */
    public function config(string $side): array
    {
        return match ($side) {
            'ar' => [
                'side' => 'ar',
                'party' => Customer::class, 'doc' => Invoice::class, 'docParty' => 'customer_id', 'docPrefix' => 'INV-', 'creditPrefix' => 'CN-',
                'payTable' => 'payments', 'payParty' => 'customer_id',
                'normal' => 'D', 'groupSetting' => fn () => SalesSetting::find(1)?->ar_group_id,
                'ledgerNote' => 'Customer account (made automatically by Sales)',
                'one' => 'Customer', 'many' => 'Customers', 'route' => 'customers', 'groupName' => 'Accounts Receivable', 'settingsHint' => 'Sales → Settings',
            ],
            'ap' => [
                'side' => 'ap',
                'party' => Vendor::class, 'doc' => Bill::class, 'docParty' => 'vendor_id', 'docPrefix' => 'BILL-', 'creditPrefix' => 'VC-',
                'payTable' => 'vendor_payments', 'payParty' => 'vendor_id',
                'normal' => 'C', 'groupSetting' => fn () => PurchaseSetting::find(1)?->ap_group_id,
                'ledgerNote' => 'Vendor account (made automatically by Purchases)',
                'one' => 'Vendor', 'many' => 'Vendors', 'route' => 'vendors', 'groupName' => 'Accounts Payable', 'settingsHint' => 'Purchases → Settings',
            ],
        };
    }

    /** All parties A-Z, each with a `balance` (closing, as of $asOf or today). */
    public function all(string $side, ?string $asOf = null)
    {
        $c = $this->config($side);
        $parties = $c['party']::orderBy('name')->get();
        $bal = $this->balances->closingBalances($parties->pluck('ledger_id')->all(), $asOf);
        $zero = ['dc' => $c['normal'], 'amount' => '0.00', 'dr_total' => '0.00', 'cr_total' => '0.00'];

        return $parties->each(fn ($p) => $p->setAttribute('balance', $bal[(int) $p->ledger_id] ?? $zero));
    }

    /** Summary bar numbers plus which parties are overdue / due soon (for filters). */
    public function stats(string $side, $parties = null): array
    {
        $c = $this->config($side);
        $parties ??= $this->all($side);
        $today = now()->toDateString();

        $overdue = $this->pastDue($c, '<', $today);
        $soon = $side === 'ap' ? $this->pastDue($c, '>=', $today, now()->addDays(6)->toDateString(), array_keys($overdue)) : [];

        $out = '0.00';
        $overdueAmt = '0.00';
        $soonAmt = '0.00';
        $credit = 0;
        $paid = 0;

        foreach ($parties as $p) {
            $b = $p->balance;
            if (Money::isZero($b['amount'])) {
                $paid++;

                continue;
            }
            if ($b['dc'] !== $c['normal']) {
                $credit++;

                continue;
            }
            $out = Money::add($out, $b['amount']);
            $left = $b['amount'];
            // overdue comes off the balance first, then what is due soon - never more than is actually owed
            $takeOverdue = isset($overdue[$p->id]) ? (Money::cmp($overdue[$p->id], $left) > 0 ? $left : $overdue[$p->id]) : '0.00';
            $overdueAmt = Money::add($overdueAmt, $takeOverdue);
            $left = Money::sub($left, $takeOverdue);
            if (isset($soon[$p->id]) && Money::positive($left)) {
                $soonAmt = Money::add($soonAmt, Money::cmp($soon[$p->id], $left) > 0 ? $left : $soon[$p->id]);
            }
        }

        $stats = [
            'total' => $parties->count(), 'outstanding' => $out, 'overdue' => $overdueAmt, 'due_soon' => $soonAmt,
            'credit' => $credit, 'paid' => $paid,
            'overdue_ids' => array_keys($overdue), 'due_soon_ids' => array_keys($soon),
        ];

        if ($side === 'ar' && Money::billableExpensesEnabled()) {
            $q = DB::connection('tenant')->table('billable_expenses')->whereNull('invoice_id');
            $stats['unbilled'] = (clone $q)->count();
            $stats['unbilled_ids'] = (clone $q)->distinct()->pluck('customer_id')->all();
        }

        return $stats;
    }

    /** Issued documents past a due-date test that still have something left to pay: [party_id => amount]. */
    protected function pastDue(array $c, string $op, string $from, ?string $to = null, array $skip = []): array
    {
        $db = DB::connection('tenant');
        $docs = $db->table((new $c['doc'])->getTable())->where('status', 'issued')->whereNotNull('due_date')
            ->where('due_date', $op, $from)->when($to, fn ($q) => $q->where('due_date', '<=', $to))
            ->get(['id', $c['docParty'].' as party', 'total']);

        $paid = $db->table('payment_allocations')->where('side', $c['side'])->whereIn('doc_id', $docs->pluck('id'))
            ->groupBy('doc_id')->selectRaw('doc_id, SUM(amount) AS paid')->pluck('paid', 'doc_id');

        $out = [];
        foreach ($docs as $d) {
            $due = Money::sub($d->total, $paid[$d->id] ?? 0);
            if (Money::positive($due) && ! in_array($d->party, $skip)) {
                $out[$d->party] = Money::add($out[$d->party] ?? 0, $due);
            }
        }

        return $out;
    }

    /** Create the party and its own ledger under the AR/AP group. */
    public function create(string $side, array $data)
    {
        $c = $this->config($side);
        $groupId = ($c['groupSetting'])();
        if (! $groupId) {
            $this->fail("The {$c['groupName']} group is not configured. Set it under {$c['settingsHint']} first.");
        }

        return DB::connection('tenant')->transaction(function () use ($c, $data, $groupId) {
            $ledger = Ledger::create([
                'group_id' => $groupId, 'name' => $data['name'], 'code' => $this->chart->nextLedgerCode((int) $groupId),
                'op_balance' => '0.00', 'op_balance_dc' => $c['normal'], 'type' => 0, 'reconciliation' => 0, 'notes' => $c['ledgerNote'],
            ]);

            $extra = $c['side'] === 'ap' ? ['expense_ledger_id' => $data['expense_ledger_id'] ?? null] : [];

            return $c['party']::create(['ledger_id' => $ledger->id] + $extra + $this->fields($c['side'], $data) + ['status' => 1, 'created_at' => now()]);
        });
    }

    public function update($party, array $data): void
    {
        $side = $party instanceof Customer ? 'ar' : 'ap';
        DB::connection('tenant')->transaction(function () use ($party, $data, $side) {
            $extra = $side === 'ap' ? ['expense_ledger_id' => $data['expense_ledger_id'] ?? null] : [];
            $party->update($this->fields($side, $data) + ['status' => ! empty($data['status']) ? 1 : 0] + $extra);
            // keep the subsidiary ledger's display name in sync
            Ledger::where('id', $party->ledger_id)->update(['name' => $data['name']]);
        });
    }

    /** Blocked while documents exist. The ledger is only removed if nothing was ever posted to it. */
    public function delete($party): void
    {
        $side = $party instanceof Customer ? 'ar' : 'ap';
        $c = $this->config($side);
        $db = DB::connection('tenant');

        if ($side === 'ar' && ($db->table('quotations')->where('customer_id', $party->id)->exists() || $db->table('invoices')->where('customer_id', $party->id)->exists())) {
            $this->fail('This customer has quotations or invoices on file and cannot be deleted.');
        }
        if ($side === 'ap' && $db->table('bills')->where('vendor_id', $party->id)->exists()) {
            $this->fail('This vendor has bills on file and cannot be deleted.');
        }

        $db->transaction(function () use ($party, $side, $db) {
            $posted = $db->table('entryitems')->where('ledger_id', $party->ledger_id)->exists();
            if ($side === 'ar') {
                CustomerVehicle::where('customer_id', $party->id)->delete();
            }
            $party->delete();
            if (! $posted) {
                Ledger::where('id', $party->ledger_id)->delete();
            }
        });
    }

    /**
     * Up to 4 vehicle slots (customers only). Existing rows are updated in place by id so a vehicle's
     * id stays stable - quotations and invoices point at it. A blank slot removes its row.
     */
    public function saveVehicles(Customer $customer, array $slots): void
    {
        $used = 0;
        foreach ($slots as $v) {
            $model = trim((string) ($v['model'] ?? ''));
            $plate = trim((string) ($v['plate_number'] ?? ''));
            // never trust a posted id - only touch a row that belongs to this customer
            $row = ! empty($v['id']) ? CustomerVehicle::where('customer_id', $customer->id)->find($v['id']) : null;

            if (($model === '' && $plate === '') || $used >= 4) {
                $row?->delete();

                continue;
            }

            $attrs = [
                'model' => $model ?: null, 'plate_number' => $plate ?: null,
                'vehicle_model_id' => ! empty($v['vehicle_model_id']) ? (int) $v['vehicle_model_id'] : null, 'sort_order' => $used,
            ];
            $row ? $row->update($attrs) : CustomerVehicle::create($attrs + ['customer_id' => $customer->id]);
            $used++;
        }
    }

    /**
     * Statement for one party's ledger, oldest first with a running balance.
     * $start/$end are optional Y-m-d; opening = everything before $start, closing = as of $end.
     */
    public function statement(string $side, $party, ?string $start = null, ?string $end = null): array
    {
        $c = $this->config($side);
        $db = DB::connection('tenant');
        $opening = $this->balances->openingBalance($party->ledger_id, $start);
        $closing = $this->balances->closingBalance($party->ledger_id, $end);

        $rows = $db->table('entryitems')->leftJoin('entries', 'entries.id', '=', 'entryitems.entry_id')
            ->where('entryitems.ledger_id', $party->ledger_id)
            ->when($start, fn ($q) => $q->where('entries.date', '>=', $start))->when($end, fn ($q) => $q->where('entries.date', '<=', $end))
            ->orderBy('entries.date')->orderBy('entries.id')
            ->get(['entries.id as entry_id', 'entries.number', 'entries.entrytype_id', 'entries.date', 'entries.notes as entry_notes', 'entryitems.narration', 'entryitems.dc', 'entryitems.amount']);

        $docs = $db->table((new $c['doc'])->getTable())->where($c['docParty'], $party->id)->whereNotNull('entry_id')->get(['id', 'number', 'entry_id'])->keyBy('entry_id');
        $pays = $db->table($c['payTable'])->where($c['payParty'], $party->id)->whereNotNull('entry_id')->get(['id', 'number', 'entry_id', 'amount'])->keyBy('entry_id');
        $credits = $db->table('credit_notes')->where('side', $side)->where('party_id', $party->id)->whereNotNull('entry_id')->get(['id', 'number', 'kind', 'entry_id'])->keyBy('entry_id');
        $refunds = $db->table('credit_refunds')->where('side', $side)->where('party_id', $party->id)->whereNotNull('entry_id')->get(['src_id', 'entry_id'])->keyBy('entry_id');
        $types = $db->table('entrytypes')->get()->keyBy('id');

        // running balance as one signed figure, positive on the ledger's normal side
        $running = $opening['dc'] === $c['normal'] ? $opening['amount'] : Money::neg($opening['amount']);
        $tx = [];

        foreach ($rows as $r) {
            $running = $r->dc === $c['normal'] ? Money::add($running, $r->amount) : Money::sub($running, $r->amount);

            if ($doc = $docs->get($r->entry_id)) {
                [$type, $label] = ['document', $c['docPrefix'].str_pad($doc->number, 4, '0', STR_PAD_LEFT)];
            } elseif ($pay = $pays->get($r->entry_id)) {
                [$type, $label] = ['payment', (string) (int) $pay->number];
            } elseif ($cn = $credits->get($r->entry_id)) {
                [$type, $label] = ['credit_note', ($cn->kind === 'writeoff' ? 'WO-' : $c['creditPrefix']).str_pad($cn->number, 4, '0', STR_PAD_LEFT)];
            } elseif ($refunds->has($r->entry_id)) {
                [$type, $label] = ['refund', 'Refund'];
            } else {
                $t = $types->get($r->entrytype_id);
                [$type, $label] = ['other', $t ? $t->prefix.($t->zero_padding > 0 ? str_pad($r->number, $t->zero_padding, '0', STR_PAD_LEFT) : $r->number).$t->suffix : '#'.$r->number];
            }

            $tx[] = [
                'date' => $r->date, 'type' => $type, 'label' => $label, 'entry_id' => $r->entry_id,
                'narration' => $r->narration ?: $r->entry_notes, 'dc' => $r->dc, 'amount' => $r->amount,
                'running' => $running, 'balance_dc' => Money::cmp($running, 0) >= 0 ? $c['normal'] : ($c['normal'] === 'D' ? 'C' : 'D'),
                'balance_amount' => ltrim($running, '-'),
            ];
        }

        // vendor payments posted straight to an expense account never touch the AP ledger: list them, no running balance
        if ($side === 'ap') {
            $seen = $rows->pluck('entry_id')->flip();
            $direct = $pays->keys()->reject(fn ($id) => $seen->has($id));
            if ($direct->isNotEmpty()) {
                $drows = $db->table('entryitems')->leftJoin('entries', 'entries.id', '=', 'entryitems.entry_id')
                    ->whereIn('entryitems.entry_id', $direct)->where('entryitems.dc', 'D')
                    ->get(['entries.id as entry_id', 'entries.date', 'entries.notes as entry_notes', 'entryitems.narration']);
                foreach ($drows as $r) {
                    $p = $pays[$r->entry_id];
                    $tx[] = ['date' => $r->date, 'type' => 'payment', 'label' => (int) $p->number.' (direct to expense)', 'entry_id' => $r->entry_id,
                        'narration' => $r->narration ?: $r->entry_notes, 'dc' => 'D', 'amount' => $p->amount, 'running' => null, 'balance_dc' => null, 'balance_amount' => null];
                }
                usort($tx, fn ($a, $b) => [$a['date'], $a['entry_id']] <=> [$b['date'], $b['entry_id']]);
            }
        }

        return compact('opening', 'closing') + ['transactions' => $tx];
    }

    protected function fields(string $side, array $d): array
    {
        return [
            'name' => $d['name'], 'contact_person' => $d['contact_person'] ?? null, 'phone' => $d['phone'] ?? null,
            'email' => $d['email'] ?? null, 'address' => $d['address'] ?? null, 'notes' => $d['notes'] ?? null,
        ];
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['party' => $message]);
    }
}
