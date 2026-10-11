<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Vendor;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Services\Purchases\PurchasesService;
use App\Services\Sales\Allocations;
use App\Services\Sales\SalesService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Payments to vendors, paid from a bank or cash account and optionally applied to bills. */
class VendorPaymentController extends Controller
{
    public function __construct(protected PurchasesService $purchases, protected SalesService $sales, protected Allocations $allocs, protected EntryService $entries, protected ChartOfAccounts $chart) {}

    public function index()
    {
        $rows = DB::connection('tenant')->table('vendor_payments')->join('vendors', 'vendors.id', '=', 'vendor_payments.vendor_id')
            ->orderByDesc('vendor_payments.date')->orderByDesc('vendor_payments.id')->get(['vendor_payments.*', 'vendors.name as vendor_name']);
        $ids = $rows->pluck('id')->all();

        return view('purchases.payments', ['rows' => $rows, 'applied' => $this->allocs->appliedBySource('ap', $ids), 'refunded' => $this->allocs->refundedBySource('ap', $ids, 'pay')]);
    }

    protected function methods(): array
    {
        return DB::connection('tenant')->table('payment_methods')->orderBy('name')->pluck('name')->all();
    }

    public function create(Request $request, ?int $vendor = null)
    {
        $focus = (int) $request->query('bill');
        // vendors that have a default expense account: a payment to them (not applied to a bill) goes straight to that account
        $direct = Vendor::whereNotNull('expense_ledger_id')->join('ledgers', 'ledgers.id', '=', 'vendors.expense_ledger_id')->pluck('ledgers.name', 'vendors.id');

        return view('purchases.payment-form', [
            'payment' => null, 'vendors' => Vendor::where('status', 1)->orderBy('name')->get(['id', 'name']), 'banks' => $this->entries->accountOptions(4), 'methods' => $this->methods(),
            'selectedVendor' => $vendor ?? ($focus ? DB::connection('tenant')->table('bills')->where('id', $focus)->value('vendor_id') : null),
            'amount' => $request->query('amount'), 'focus' => $focus, 'nextNumber' => $this->sales->nextNumber('vendor_payments'), 'direct' => $direct,
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'vendor_id' => ['required', 'integer'], 'ledger_id' => ['required', 'integer'], 'date' => ['required', 'date'], 'amount' => ['required', 'string'],
            'number' => ['nullable', 'string', 'max:12'], 'method' => ['nullable', 'string', 'max:50'], 'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:500'], 'apply' => ['nullable', 'array'],
        ]);
        $id = $this->purchases->payVendor($d);
        $to = Money::positive($this->allocs->available('ap', 'pay', $id)) && DB::connection('tenant')->table('bills')->where('vendor_id', $d['vendor_id'])->where('status', 'issued')->exists()
            ? route('vendor-payments.show', $id) : route('vendor-payments.index');

        return redirect($request->has('save_and_new') ? route('vendor-payments.create') : $to)->with('message', 'Payment recorded and posted to the books.');
    }

    public function show(int $id)
    {
        $p = $this->purchases->payment($id);
        abort_unless($p, 404);
        $apps = $this->allocs->forSource('ap', $id);
        $left = $this->allocs->available('ap', 'pay', $id);

        return view('purchases.payment-show', [
            'p' => $p, 'apps' => $apps, 'appliedTotal' => array_reduce($apps, fn ($c, $a) => Money::add($c, $a->amount), '0.00'), 'unapplied' => $left,
            'openDocs' => Money::positive($left) ? $this->allocs->openDocs('ap', $p->vendor_id) : [],
            'refunds' => app(\App\Services\Credits\CreditService::class)->refundsFor('ap', 'pay', $id), 'banks' => Money::positive($left) ? app(\App\Services\Accounting\EntryService::class)->accountOptions(4) : [],
            'methods' => \Illuminate\Support\Facades\DB::connection('tenant')->table('payment_methods')->orderBy('name')->pluck('name')->all(), 'ui' => app(\App\Services\Credits\CreditService::class)->cfg('ap'),
        ]);
    }

    public function edit(int $id)
    {
        $p = $this->purchases->payment($id);
        abort_unless($p, 404);
        if ($p->status !== 'paid') {
            return redirect()->route('vendor-payments.show', $id)->with('error', 'Only a paid payment can be edited.');
        }

        return view('purchases.payment-form', [
            'payment' => $p, 'vendors' => collect(), 'banks' => $this->entries->accountOptions(4), 'methods' => array_unique(array_filter(array_merge($this->methods(), [$p->method]))),
            'selectedVendor' => $p->vendor_id, 'amount' => null, 'focus' => 0, 'nextNumber' => null, 'direct' => collect(), 'used' => $this->purchases->paymentUsed($id),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $d = $request->validate([
            'ledger_id' => ['required', 'integer'], 'date' => ['required', 'date'], 'amount' => ['required', 'string'], 'number' => ['nullable', 'string', 'max:12'],
            'method' => ['nullable', 'string', 'max:50'], 'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $changes = $this->purchases->updatePayment($id, $d);

        return redirect()->route('vendor-payments.show', $id)->with('message', $changes ? 'Payment updated. The books were corrected.' : 'Nothing changed.');
    }

    public function apply(Request $request, int $id)
    {
        abort_if($this->chart->companyLocked(), 403, 'This company account is locked.');
        $this->allocs->allocate('ap', $id, (array) $request->input('apply'), 'manual');
        ActivityLog::add("Applied vendor payment #$id to bills");

        return redirect()->route('vendor-payments.show', $id)->with('message', 'Payment applied.');
    }

    public function removeAllocation(Request $request, int $allocationId)
    {
        abort_if($this->chart->companyLocked(), 403, 'This company account is locked.');
        $row = $this->allocs->remove('ap', $allocationId);
        $back = (string) $request->input('return');
        $to = preg_match('#^bill:(\d+)$#', $back, $m) ? route('bills.show', $m[1]) : route('vendor-payments.show', $row->payment_id);

        return redirect($to)->with('message', 'Removed. That amount is now a prepayment to the vendor again.');
    }

    public function void(int $id)
    {
        $this->purchases->voidPayment($id);

        return redirect()->route('vendor-payments.show', $id)->with('message', 'Payment voided and its journal entry reversed.');
    }

    public function destroy(int $id)
    {
        $this->purchases->deletePayment($id);

        return redirect()->route('vendor-payments.index')->with('message', 'Payment deleted.');
    }

    /** JSON: a vendor's unpaid bills, oldest first, for the Pay Vendor form. */
    public function openBills(int $vendor)
    {
        abort_unless(Vendor::whereKey($vendor)->exists(), 404);
        $today = now()->toDateString();
        $docs = array_map(fn ($d) => ['id' => $d->id, 'label' => $d->label, 'date' => Money::date($d->date), 'due' => $d->due_date ? Money::date($d->due_date) : '',
            'overdue' => $d->due_date && substr($d->due_date, 0, 10) < $today, 'total' => $d->total, 'balance_due' => $d->balance_due], $this->allocs->openDocs('ap', $vendor));

        return response()->json(['ok' => true, 'docs' => $docs, 'credit' => $this->allocs->unappliedTotal('ap', $vendor)]);
    }
}
