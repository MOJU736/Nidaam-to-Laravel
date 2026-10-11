<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesPurchaseDocuments;
use App\Services\Accounting\ActivityLog;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Purchases\PurchasesService;
use App\Services\Sales\Allocations;
use App\Services\Sales\SalesService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillController extends Controller
{
    use HandlesPurchaseDocuments;

    public function __construct(protected PurchasesService $purchases, protected SalesService $sales, protected Allocations $allocs) {}

    public function index(Request $request)
    {
        $rows = DB::connection('tenant')->table('bills')->join('vendors', 'vendors.id', '=', 'bills.vendor_id')
            ->when($request->query('status') === 'draft', fn ($q) => $q->where('bills.status', 'draft'))
            ->orderByDesc('bills.date')->orderByDesc('bills.id')->get(['bills.*', 'vendors.name as vendor_name']);

        return view('purchases.bills', ['rows' => $this->allocs->decorate('ap', $rows), 'purchases' => $this->purchases, 'draftsOnly' => $request->query('status') === 'draft']);
    }

    public function create(Request $request, ?int $vendor = null)
    {
        return view('purchases.doc-form', $this->billFormData($this->purchases, $this->sales, 'bill', null, $vendor ?? ((int) $request->query('vendor') ?: null)));
    }

    public function store(Request $request)
    {
        $d = $this->validateBillDoc($request, 'bills', null, $this->sales);
        $id = $this->purchases->saveBill($d, $this->collectBillLines($request, $this->sales));
        ActivityLog::add("Created draft Bill #$id");

        return $this->afterSave($request, $id, 'Bill saved as draft.');
    }

    public function show(int $id)
    {
        $b = $this->purchases->bill($id);
        abort_unless($b, 404);
        $extra = ['applied' => [], 'credit' => '0.00'];
        if ($b->status === 'issued') {
            $b = (object) array_merge((array) $b, (array) $this->allocs->decorate('ap', [$b])[0]);
            $extra = ['applied' => $this->allocs->forDoc('ap', $id), 'credit' => $this->allocs->unappliedTotal('ap', $b->vendor_id)];
        }

        return view('purchases.bill-show', $extra + ['b' => $b, 'purchases' => $this->purchases]);
    }

    public function edit(int $id)
    {
        $b = $this->purchases->bill($id);
        abort_unless($b, 404);
        if ($b->status !== 'draft') {
            return redirect()->route('bills.show', $id)->with('error', $b->status === 'issued' ? 'An issued bill is locked - revert it to draft to change it.' : 'Voided bills cannot be edited.');
        }

        return view('purchases.doc-form', $this->billFormData($this->purchases, $this->sales, 'bill', $b, null));
    }

    public function update(Request $request, int $id)
    {
        $b = $this->purchases->bill($id);
        abort_unless($b && $b->status === 'draft', 404);
        $d = $this->validateBillDoc($request, 'bills', $id, $this->sales);
        $this->purchases->saveBill($d, $this->collectBillLines($request, $this->sales), $id);
        ActivityLog::add("Updated draft Bill #$id");

        return $this->afterSave($request, $id, 'Bill updated.');
    }

    /** "Save & issue": save, then issue in the same click. If issuing fails the bill stays a draft and the reason is shown. */
    protected function afterSave(Request $request, int $id, string $message)
    {
        if ($request->input('then') === 'issue') {
            try {
                $this->purchases->issueBill($id);

                return redirect()->route('bills.show', $id)->with('message', 'Bill saved and issued.');
            } catch (ValidationException $e) {
                return redirect()->route('bills.show', $id)->with('error', 'Saved as a draft, but it could not be issued: '.$e->validator->errors()->first());
            }
        }

        return redirect()->route('bills.show', $id)->with('message', $message);
    }

    public function issue(int $id)
    {
        $this->purchases->issueBill($id);

        return redirect()->route('bills.show', $id)->with('message', 'Bill issued and posted to the books.');
    }

    public function void(int $id)
    {
        $this->purchases->voidBill($id);

        return redirect()->route('bills.show', $id)->with('message', 'Bill voided and its journal entry reversed.');
    }

    public function revert(int $id)
    {
        $this->purchases->revertBill($id);

        return redirect()->route('bills.edit', $id)->with('message', 'Bill reverted to draft - its journal entry was reversed. Make your changes and Issue again when ready.');
    }

    public function destroy(int $id)
    {
        $this->purchases->deleteBill($id);

        return redirect()->route('bills.index')->with('message', 'Bill deleted.');
    }

    /** Uses the vendor's prepayments and supplier credits, oldest first, to pay this bill - only when asked. */
    public function applyCredit(int $id)
    {
        $b = $this->purchases->bill($id);
        abort_unless($b, 404);
        if ($b->status !== 'issued') {
            return redirect()->route('bills.index')->with('error', 'Only an issued bill can be paid.');
        }
        abort_if(app(ChartOfAccounts::class)->companyLocked(), 403, 'This company account is locked.');

        $due = $this->allocs->decorate('ap', [$b])[0]->balance_due;
        $used = '0.00';
        DB::connection('tenant')->transaction(function () use ($b, &$due, &$used) {
            foreach ($this->allocs->openCredits('ap', $b->vendor_id) as $src) {
                if (! Money::positive($due)) {
                    break;
                }
                $take = Money::cmp($src->unapplied, $due) < 0 ? $src->unapplied : $due;
                $this->allocs->allocate('ap', $src->id, [$b->id => $take], 'credit', $src->type);
                $due = Money::sub($due, $take);
                $used = Money::add($used, $take);
            }
        });
        if (Money::positive($used)) {
            ActivityLog::add('Applied '.Money::format($used)." credit to Purchase Bill #{$b->number}");

            return redirect()->route('bills.show', $id)->with('message', 'Applied '.Money::format($used).' of credit to this bill.');
        }

        return redirect()->route('bills.show', $id)->with('warning', 'This vendor has no unapplied credit to use.');
    }
}
