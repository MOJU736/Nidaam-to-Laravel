<?php

namespace App\Http\Controllers;

use App\Models\Tenant\Customer;
use App\Models\Tenant\Ledger;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\EntryService;
use App\Services\Sales\Allocations;
use App\Services\Sales\SalesService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Customer payments: received into a bank or cash account, optionally applied to invoices. */
class PaymentController extends Controller
{
    public function __construct(protected SalesService $sales, protected Allocations $allocs, protected EntryService $entries, protected ChartOfAccounts $chart) {}

    public function index()
    {
        $rows = DB::connection('tenant')->table('payments')->join('customers', 'customers.id', '=', 'payments.customer_id')
            ->orderByDesc('payments.date')->orderByDesc('payments.id')->get(['payments.*', 'customers.name as customer_name']);
        $ids = $rows->pluck('id')->all();

        return view('sales.payments', ['rows' => $rows, 'applied' => $this->allocs->appliedBySource('ar', $ids), 'refunded' => $this->allocs->refundedBySource('ar', $ids, 'pay')]);
    }

    protected function banks(): array
    {
        return $this->entries->accountOptions(4);
    }

    protected function methods(): array
    {
        return DB::connection('tenant')->table('payment_methods')->orderBy('name')->pluck('name')->all();
    }

    public function create(Request $request, ?int $customer = null)
    {
        $focus = (int) $request->query('invoice');

        return view('sales.payment-form', [
            'payment' => null, 'customers' => Customer::where('status', 1)->orderBy('name')->get(['id', 'name']), 'banks' => $this->banks(), 'methods' => $this->methods(),
            'selectedCustomer' => $customer ?? ($focus ? DB::connection('tenant')->table('invoices')->where('id', $focus)->value('customer_id') : null),
            'amount' => $request->query('amount'), 'focus' => $focus, 'nextNumber' => $this->sales->nextNumber('payments'), 'wht' => $this->sales->withholdingEnabled(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'customer_id' => ['required', 'integer'], 'ledger_id' => ['required', 'integer'], 'date' => ['required', 'date'], 'amount' => ['required', 'string'],
            'number' => ['nullable', 'string', 'max:12'], 'method' => ['nullable', 'string', 'max:50'], 'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:500'],
            'wht_rate' => ['nullable', 'string'], 'apply' => ['nullable', 'array'],
        ]);
        $d['wht_rate'] = $this->sales->withholdingEnabled() && $request->boolean('wht_on') ? ($d['wht_rate'] ?? 0) : 0;
        $id = $this->sales->receivePayment($d);
        $left = $this->allocs->available('ar', 'pay', $id);

        // money left over (not applied to any invoice) goes to the payment's own page to apply or keep as credit
        $to = Money::positive($left) ? route('payments.show', $id) : route('payments.index');

        return redirect($request->has('save_and_new') ? route('payments.create') : $to)->with('message', 'Payment received and posted to the books.');
    }

    public function show(int $id)
    {
        $p = $this->sales->payment($id);
        abort_unless($p, 404);
        $apps = $this->allocs->forSource('ar', $id);
        $left = $this->allocs->available('ar', 'pay', $id);
        $has = Money::positive($left);

        return view('sales.payment-show', [
            'p' => $p, 'apps' => $apps, 'appliedTotal' => array_reduce($apps, fn ($c, $a) => Money::add($c, $a->amount), '0.00'), 'unapplied' => $left,
            'openDocs' => $has ? $this->allocs->openDocs('ar', $p->customer_id) : [], 'email' => Customer::whereKey($p->customer_id)->value('email'),
        ]);
    }

    public function edit(int $id)
    {
        $p = $this->sales->payment($id);
        abort_unless($p, 404);
        if ($p->status !== 'received') {
            return redirect()->route('payments.show', $id)->with('error', 'Only a received payment can be edited.');
        }

        return view('sales.payment-form', [
            'payment' => $p, 'customers' => collect(), 'banks' => $this->banks(), 'methods' => array_unique(array_filter(array_merge($this->methods(), [$p->method]))),
            'selectedCustomer' => $p->customer_id, 'amount' => null, 'focus' => 0, 'nextNumber' => null,
            'wht' => $this->sales->withholdingEnabled() || Money::positive($p->wht_amount ?? 0), 'used' => $this->sales->paymentUsed($id),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $d = $request->validate([
            'ledger_id' => ['required', 'integer'], 'date' => ['required', 'date'], 'amount' => ['required', 'string'], 'number' => ['nullable', 'string', 'max:12'],
            'method' => ['nullable', 'string', 'max:50'], 'reference' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:500'], 'wht_rate' => ['nullable', 'string'],
        ]);
        $d['wht_rate'] = $request->boolean('wht_on') ? ($d['wht_rate'] ?? 0) : 0;
        $changes = $this->sales->updatePayment($id, $d);

        return redirect()->route('payments.show', $id)->with('message', $changes ? 'Payment updated. The books were corrected.' : 'Nothing changed.');
    }

    /** "Apply credit" on a payment's page: apply what's left of it to invoices. */
    public function apply(Request $request, int $id)
    {
        abort_if($this->chart->companyLocked(), 403, 'This company account is locked.');
        $this->allocs->allocate('ar', $id, (array) $request->input('apply'), 'manual');
        ActivityLog::add("Applied payment #$id to invoices");

        return redirect()->route('payments.show', $id)->with('message', 'Payment applied.');
    }

    /** "Remove" on one application: the amount goes back to unapplied credit. */
    public function removeAllocation(Request $request, int $allocationId)
    {
        abort_if($this->chart->companyLocked(), 403, 'This company account is locked.');
        $row = $this->allocs->remove('ar', $allocationId);
        $back = (string) $request->input('return');
        $to = preg_match('#^invoice:(\d+)$#', $back, $m) ? route('invoices.show', $m[1]) : route('payments.show', $row->payment_id);

        return redirect($to)->with('message', 'Removed. That amount is now unapplied credit again.');
    }

    public function void(int $id)
    {
        $this->sales->voidPayment($id);

        return redirect()->route('payments.show', $id)->with('message', 'Payment voided and its journal entry reversed.');
    }

    public function destroy(int $id)
    {
        $this->sales->deletePayment($id);

        return redirect()->route('payments.index')->with('message', 'Payment deleted.');
    }

    /** JSON: a customer's unpaid invoices, oldest first, for the Receive Payment form. */
    public function openInvoices(int $customer)
    {
        abort_unless(Customer::whereKey($customer)->exists(), 404);
        $today = now()->toDateString();
        $docs = array_map(fn ($d) => ['id' => $d->id, 'label' => $d->label, 'date' => Money::date($d->date), 'due' => $d->due_date ? Money::date($d->due_date) : '',
            'overdue' => $d->due_date && substr($d->due_date, 0, 10) < $today, 'total' => $d->total, 'balance_due' => $d->balance_due], $this->allocs->openDocs('ar', $customer));

        return response()->json(['ok' => true, 'docs' => $docs, 'credit' => $this->allocs->unappliedTotal('ar', $customer)]);
    }
}
