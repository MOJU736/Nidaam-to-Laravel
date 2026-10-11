<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesSalesDocuments;
use App\Models\Tenant\CustomerVehicle;
use App\Services\Accounting\ActivityLog;
use App\Services\Sales\Allocations;
use App\Services\Sales\SalesService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    use HandlesSalesDocuments;

    public function __construct(protected SalesService $sales, protected Allocations $allocs) {}

    public function index(Request $request)
    {
        $rows = DB::connection('tenant')->table('invoices')->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->when($request->query('status') === 'draft', fn ($q) => $q->where('invoices.status', 'draft'))
            ->orderByDesc('invoices.date')->orderByDesc('invoices.id')->get(['invoices.*', 'customers.name as customer_name']);

        return view('sales.invoices', [
            'rows' => $this->allocs->decorate('ar', $rows), 'sales' => $this->sales, 'draftsOnly' => $request->query('status') === 'draft',
            'quotations' => DB::connection('tenant')->table('quotations')->join('customers', 'customers.id', '=', 'quotations.customer_id')->where('quotations.status', '!=', 'converted')
                ->orderByDesc('quotations.id')->limit(5)->get(['quotations.id', 'quotations.number', 'quotations.total', 'customers.name as customer_name']),
        ]);
    }

    public function create(Request $request, ?int $customer = null)
    {
        return view('sales.doc-form', $this->formData($this->sales, 'invoice', null, $customer ?? (int) $request->query('customer') ?: null));
    }

    public function store(Request $request)
    {
        $d = $this->validateDoc($request, 'invoices', null, $this->sales);
        $id = $this->sales->saveInvoice($d, $this->collectLines($request, $this->sales));
        ActivityLog::add("Created draft Invoice #$id");

        return $this->afterSave($request, $id, 'Invoice saved as draft.');
    }

    public function show(int $id)
    {
        $inv = $this->sales->invoice($id);
        abort_unless($inv, 404);
        $extra = [];
        if ($inv->status === 'issued') {
            $inv = (object) array_merge((array) $inv, (array) $this->allocs->decorate('ar', [$inv])[0]);
            $extra = ['applied' => $this->allocs->forDoc('ar', $id), 'credit' => $this->allocs->unappliedTotal('ar', $inv->customer_id)];
        }

        return view('sales.invoice-show', $extra + [
            'inv' => $inv, 'sales' => $this->sales, 'belowCost' => $inv->status === 'draft' ? $this->sales->belowCost($inv) : [],
            'vehicles' => $inv->vehicle_ids ? CustomerVehicle::whereIn('id', $inv->vehicle_ids)->get()->map(fn ($v) => trim(($v->model ?? '').' '.($v->plate_number ? '- '.$v->plate_number : '')))->all() : [],
            'applied' => $extra['applied'] ?? [], 'credit' => $extra['credit'] ?? '0.00',
        ]);
    }

    public function edit(int $id)
    {
        $inv = $this->sales->invoice($id);
        abort_unless($inv, 404);
        if ($inv->status === 'void') {
            return redirect()->route('invoices.show', $id)->with('error', 'Voided invoices cannot be edited.');
        }
        // an issued invoice only gets a quick edit: its amounts are what the posted entry reflects
        if ($inv->status === 'issued') {
            return view('sales.invoice-details', ['inv' => $inv, 'sales' => $this->sales]);
        }

        return view('sales.doc-form', $this->formData($this->sales, 'invoice', $inv, null));
    }

    public function update(Request $request, int $id)
    {
        $inv = $this->sales->invoice($id);
        abort_unless($inv, 404);
        if ($inv->status === 'void') {
            return redirect()->route('invoices.show', $id)->with('error', 'Voided invoices cannot be edited.');
        }
        if ($inv->status === 'issued') {
            $d = $request->validate(['due_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:500']]);
            $this->sales->updateIssuedDetails($id, $d['due_date'] ?? null, $d['notes'] ?? null);
            ActivityLog::add("Updated due date/notes on Invoice #$id");

            return redirect()->route('invoices.show', $id)->with('message', 'Invoice updated.');
        }
        $d = $this->validateDoc($request, 'invoices', $id, $this->sales);
        $this->sales->saveInvoice($d, $this->collectLines($request, $this->sales), $id);
        ActivityLog::add("Updated draft Invoice #$id");

        return $this->afterSave($request, $id, 'Invoice updated.');
    }

    /** "Save & issue": save, then issue in the same click. If issuing fails the invoice stays a draft and the reason is shown. */
    protected function afterSave(Request $request, int $id, string $message)
    {
        if ($request->input('then') === 'issue') {
            try {
                $this->sales->issueInvoice($id);

                return redirect()->route('invoices.show', $id)->with('message', 'Invoice saved and issued.');
            } catch (\Illuminate\Validation\ValidationException $e) {
                return redirect()->route('invoices.show', $id)->with('error', 'Saved as a draft, but it could not be issued: '.$e->validator->errors()->first());
            }
        }

        return redirect()->route('invoices.show', $id)->with('message', $message);
    }

    public function issue(int $id)
    {
        $this->sales->issueInvoice($id);

        return redirect()->route('invoices.show', $id)->with('message', 'Invoice issued and posted to the books.');
    }

    public function void(int $id)
    {
        $this->sales->voidInvoice($id);

        return redirect()->route('invoices.show', $id)->with('message', 'Invoice voided and its journal entry reversed.');
    }

    public function revert(int $id)
    {
        $this->sales->revertInvoice($id);

        return redirect()->route('invoices.edit', $id)->with('message', 'Invoice reverted to draft - its journal entry was reversed. Make your changes and Issue again when ready.');
    }

    public function destroy(int $id)
    {
        $quotationId = $this->sales->deleteInvoice($id);

        return redirect()->route('invoices.index')->with('message', $quotationId ? 'Invoice deleted. Its source quotation is back to draft and can be converted to an invoice again.' : 'Invoice deleted.');
    }

    /** Uses the customer's unapplied credit, oldest first, to pay this invoice - only when asked, never automatically. */
    public function applyCredit(int $id)
    {
        $inv = $this->sales->invoice($id);
        abort_unless($inv, 404);
        if ($inv->status !== 'issued') {
            return redirect()->route('invoices.index')->with('error', 'Only an issued invoice can be paid.');
        }
        abort_if(app(\App\Services\Accounting\ChartOfAccounts::class)->companyLocked(), 403, 'This company account is locked.');

        $due = $this->allocs->decorate('ar', [$inv])[0]->balance_due;
        $used = '0.00';
        DB::connection('tenant')->transaction(function () use ($inv, &$due, &$used) {
            foreach ($this->allocs->openCredits('ar', $inv->customer_id) as $src) {
                if (! Money::positive($due)) {
                    break;
                }
                $take = Money::cmp($src->unapplied, $due) < 0 ? $src->unapplied : $due;
                $this->allocs->allocate('ar', $src->id, [$inv->id => $take], 'credit', $src->type);
                $due = Money::sub($due, $take);
                $used = Money::add($used, $take);
            }
        });
        if (Money::positive($used)) {
            ActivityLog::add('Applied '.Money::format($used)." credit to Sales Invoice #{$inv->number}");

            return redirect()->route('invoices.show', $id)->with('message', 'Applied '.Money::format($used).' of credit to this invoice.');
        }

        return redirect()->route('invoices.show', $id)->with('warning', 'This customer has no unapplied credit to use.');
    }
}
